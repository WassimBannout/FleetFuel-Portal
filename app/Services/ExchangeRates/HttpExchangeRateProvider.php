<?php

namespace App\Services\ExchangeRates;

use App\Contracts\ExchangeRateProvider;
use App\Enums\RateSource;
use App\Exceptions\ExchangeRateFetchFailed;
use App\Support\Decimal;
use App\Support\RateObservation;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Sleep;
use JsonException;

/**
 * Live USD/LBP observation from ExchangeRate-API's open endpoint (fixed URL
 * in config/fleetfuel.php, never user input). See docs/SOURCES.md.
 *
 * - Short connect and total timeouts; at most three attempts in total.
 * - Only transient failures are retried (no connection, timeout, HTTP 5xx),
 *   with a bounded pause between attempts.
 * - A 429 ends the run at once with the provider's retry advice.
 * - Every field is checked before a value is trusted. The observation time
 *   is the provider's `time_last_update_unix`, not the fetch time.
 */
final class HttpExchangeRateProvider implements ExchangeRateProvider
{
    public function __construct(private readonly HttpFactory $http) {}

    public function latest(): RateObservation
    {
        $maxAttempts = (int) config('fleetfuel.exchange_rates.http.attempts');
        /** @var list<int> $pauses */
        $pauses = config('fleetfuel.exchange_rates.http.backoff_milliseconds');

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->send();
            } catch (ConnectionException) {
                $response = null;
            }

            if ($response?->successful()) {
                return $this->parse($response->body(), $attempt);
            }

            if ($response?->status() === 429) {
                throw ExchangeRateFetchFailed::rateLimited($this->retryAt($response), $attempt);
            }

            if ($response !== null && ! $response->serverError()) {
                throw ExchangeRateFetchFailed::httpError($response->status(), $attempt);
            }

            // No response or a 5xx: transient, so try again while attempts remain.
            if ($attempt >= $maxAttempts) {
                throw $response === null
                    ? ExchangeRateFetchFailed::connectionFailed($attempt)
                    : ExchangeRateFetchFailed::serverError($response->status(), $attempt);
            }

            Sleep::for($pauses[min($attempt, count($pauses)) - 1])->milliseconds();
        }
    }

    private function send(): Response
    {
        return $this->http
            ->acceptJson()
            ->connectTimeout((int) config('fleetfuel.exchange_rates.http.connect_timeout_seconds'))
            ->timeout((int) config('fleetfuel.exchange_rates.http.timeout_seconds'))
            // The URL is fixed; a redirect elsewhere is not followed.
            ->withOptions(['allow_redirects' => false])
            ->get((string) config('fleetfuel.exchange_rates.endpoint'));
    }

    private function parse(string $body, int $attempts): RateObservation
    {
        $fetchedAt = CarbonImmutable::now()->utc()->startOfSecond();
        $invalid = fn (string $reason, string $message) => ExchangeRateFetchFailed::invalidResponse($reason, $message, $attempts);

        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $invalid('invalid_response', 'The provider response is not valid JSON.');
        }

        if (! is_array($data) || ($data['result'] ?? null) !== 'success') {
            throw $invalid('provider_error', 'The provider did not report success.');
        }

        if (($data['base_code'] ?? null) !== 'USD') {
            throw $invalid('unexpected_base', 'The provider response is not based on USD.');
        }

        $rates = $data['rates'] ?? null;
        $rate = $this->rate(is_array($rates) ? ($rates['LBP'] ?? null) : null)
            ?? throw $invalid('invalid_rate', 'The provider sent no usable positive LBP rate.');

        $observedAt = $this->instant($data['time_last_update_unix'] ?? null)
            ?? throw $invalid('invalid_timestamp', 'The provider sent no observation time.');

        if ($observedAt->isAfter($fetchedAt->addSeconds((int) config('fleetfuel.exchange_rates.clock_skew_seconds')))) {
            throw $invalid('invalid_timestamp', 'The provider observation time is in the future.');
        }

        if ($observedAt->addHours((int) config('fleetfuel.exchange_rates.max_age_hours'))->lessThanOrEqualTo($fetchedAt)) {
            throw $invalid('stale_observation', 'The provider observation is too old to use.');
        }

        $nextUpdateAt = $this->instant($data['time_next_update_unix'] ?? null);

        return new RateObservation(
            RateSource::Provider,
            $rate,
            $observedAt,
            $fetchedAt,
            $nextUpdateAt?->isAfter($observedAt) ? $nextUpdateAt : null,
        );
    }

    /**
     * The JSON number as an eight-decimal string, or null if it is not a
     * positive number that fits DECIMAL(20,8).
     *
     * PHP decodes a JSON number into an int or float. The float is only a
     * carrier: it is written back out as its shortest exact decimal text
     * (what json_encode prints) and rounded half-up to 8 decimals at once.
     * No arithmetic ever happens on the float.
     */
    private function rate(mixed $value): ?string
    {
        if (is_int($value)) {
            $text = (string) $value;
        } elseif (is_float($value) && is_finite($value)) {
            $text = (string) json_encode($value);
        } else {
            return null;
        }

        try {
            $rate = BigDecimal::of($text)->toScale(8, RoundingMode::HalfUp);
        } catch (MathException) {
            return null;
        }

        return $rate->isPositive() && Decimal::fits((string) $rate, 20, 8) ? (string) $rate : null;
    }

    private function instant(mixed $unixSeconds): ?CarbonImmutable
    {
        return is_int($unixSeconds) && $unixSeconds > 0
            ? CarbonImmutable::createFromTimestampUTC($unixSeconds)
            : null;
    }

    /**
     * When to ask again after a 429: the Retry-After header (seconds or an
     * HTTP date, at most one day away), else the provider's documented wait.
     */
    private function retryAt(Response $response): CarbonImmutable
    {
        $now = CarbonImmutable::now()->utc()->startOfSecond();
        $header = trim($response->header('Retry-After'));

        if ($header !== '' && ctype_digit($header)) {
            return $now->addSeconds(max(1, min((int) $header, 86400)));
        }

        $date = DateTimeImmutable::createFromFormat(DATE_RFC7231, $header);
        if ($date !== false) {
            $at = CarbonImmutable::instance($date)->utc();

            if ($at->isAfter($now) && $at->lessThanOrEqualTo($now->addDay())) {
                return $at;
            }
        }

        return $now->addMinutes((int) config('fleetfuel.exchange_rates.http.rate_limited_wait_minutes'));
    }
}
