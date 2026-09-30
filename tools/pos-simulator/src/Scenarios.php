<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The named POS scenarios. Each one checks the HTTP answer and, through the
 * card balance and the station's ledger, that exactly the expected change
 * (or none) happened. The first failed check throws UnexpectedOutcome.
 *
 * replay and conflict reuse the payload of this run's accepted purchase;
 * run alone, they make one first.
 */
final class Scenarios
{
    public const NAMES = ['success', 'replay', 'conflict', 'blocked', 'quota'];

    private const FRESH_CARDS_HINT = 'Prepare fresh simulator cards with `php artisan demo:simulator-cards` and set POS_CARD, POS_BLOCKED_CARD and POS_TINY_CARD to the printed numbers (tools/pos-simulator/README.md, "Running it again").';

    /** @var array{payload: array<string, mixed>, data: array<string, mixed>}|null */
    private ?array $accepted = null;

    public function __construct(
        private readonly ApiClient $api,
        private readonly Config $config,
        private readonly Reporter $report,
    ) {}

    public function run(string $name): void
    {
        match ($name) {
            'success' => $this->success(),
            'replay' => $this->replay(),
            'conflict' => $this->conflict(),
            'blocked' => $this->blocked(),
            'quota' => $this->quota(),
            default => throw new ConfigurationError("Unknown scenario \"{$name}\"."),
        };
    }

    /** A new purchase: 201, one ledger row, and the card's usage up by exactly the liters bought. */
    private function success(): void
    {
        $card = $this->config->card;
        $liters = $this->config->liters;
        $this->report->scenario('success', 'New '.$liters.' L purchase on '.Reporter::card($card));

        $before = $this->balance($card);
        if ($before['status'] !== 'active') {
            throw new UnexpectedOutcome(Reporter::card($card)." is {$before['status']}, not active. ".self::FRESH_CARDS_HINT);
        }
        if ($before['remaining_l'] !== null && Cents::parse($before['remaining_l']) < Cents::parse($liters)) {
            throw new UnexpectedOutcome(Reporter::card($card)." has only {$before['remaining_l']} L left this month, less than {$liters} L, so the purchase would be refused. ".self::FRESH_CARDS_HINT);
        }
        $this->report->pass("balance before: {$before['used_l']} L used of ".($before['monthly_limit_l'] ?? 'unlimited').' L this month');
        $ledger = $this->ledgerCount($card);

        $payload = $this->newPayload($card, $liters);
        $response = $this->api->post('/transactions', $payload);
        $this->expectStatus($response, 201, 'The new purchase');
        $data = $response->data();

        $this->check(($data['liters'] ?? null) === $liters, "the purchase records {$liters} L");
        foreach (['unit_price_lbp', 'amount_lbp', 'amount_usd', 'rate_lbp_per_usd'] as $field) {
            $this->check(is_string($data[$field] ?? null) && preg_match('/^\d+\.\d+$/', $data[$field]) === 1, "{$field} is a decimal string");
        }
        $this->report->pass("POST /transactions {$payload['external_ref']} -> 201 Created, id {$data['id']}: {$data['amount_lbp']} LBP / {$data['amount_usd']} USD at {$data['rate_lbp_per_usd']} ({$data['rate_source']})");

        $location = $response->header('Location');
        $this->check($location !== null && str_ends_with($location, '/transactions/'.$data['id']), 'Location header points at the new purchase');
        $this->report->pass("Location: {$location}");

        $after = $this->balance($card);
        $this->check(
            Cents::parse($after['used_l']) === Cents::parse($before['used_l']) + Cents::parse($liters),
            "usage rose by exactly {$liters} L (before {$before['used_l']}, after {$after['used_l']})",
        );
        $this->report->pass("balance after: {$after['used_l']} L used (+{$liters} L, counted once)");
        $this->expectLedger($card, $ledger + 1, 'ledger for this card at this station');

        $this->accepted = ['payload' => $payload, 'data' => $data];
    }

    /** The identical request, and an equivalent spelling of it, return the original purchase without charging again. */
    private function replay(): void
    {
        $original = $this->acceptedPurchase('replay');
        $card = (string) $original['payload']['card_no'];
        $this->report->scenario('replay', "The same purchase sent again ({$original['payload']['external_ref']})");
        [$used, $ledger] = [$this->balance($card)['used_l'], $this->ledgerCount($card)];

        $response = $this->api->post('/transactions', $original['payload']);
        $this->expectStatus($response, 200, 'The identical retry');
        $this->check($response->header('Idempotency-Replayed') === 'true', 'Idempotency-Replayed: true');
        $this->check($response->data() === $original['data'], 'the original purchase is returned unchanged');
        $this->report->pass("identical payload -> 200, Idempotency-Replayed: true, same id {$original['data']['id']} and data");

        $utc = (new DateTimeImmutable((string) $original['payload']['transacted_at']))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        $equivalent = [
            'external_ref' => strtolower((string) $original['payload']['external_ref']),
            'transacted_at' => $utc,
            'liters' => Cents::shortest((string) $original['payload']['liters']),
        ] + $original['payload'];
        $response = $this->api->post('/transactions', $equivalent);
        $this->expectStatus($response, 200, 'The equivalent retry');
        $this->check(($response->data()['id'] ?? null) === $original['data']['id'], 'the same purchase id');
        $this->report->pass("equivalent payload (lower-case reference, {$utc}, \"{$equivalent['liters']}\") -> 200, same id {$original['data']['id']}");

        $this->expectUnchanged($card, $used, $ledger);
    }

    /** The same reference with different liters is refused (409) and changes nothing. */
    private function conflict(): void
    {
        $original = $this->acceptedPurchase('conflict');
        $card = (string) $original['payload']['card_no'];
        $liters = Cents::format(Cents::parse((string) $original['payload']['liters']) + 100);
        $this->report->scenario('conflict', "Same reference, {$liters} L instead of {$original['payload']['liters']} L");
        [$used, $ledger] = [$this->balance($card)['used_l'], $this->ledgerCount($card)];

        $response = $this->api->post('/transactions', ['liters' => $liters] + $original['payload']);
        $this->expectStatus($response, 409, 'The changed retry');
        $this->check($response->errorCode() === 'idempotency_conflict', 'error code idempotency_conflict');
        $this->report->pass("POST /transactions {$original['payload']['external_ref']} ({$liters} L) -> 409 idempotency_conflict");

        $this->expectUnchanged($card, $used, $ledger);
    }

    /** A blocked card is declined (403) without a ledger row. */
    private function blocked(): void
    {
        $card = $this->config->blockedCard;
        $this->report->scenario('blocked', 'Purchase on blocked '.Reporter::card($card));

        $balance = $this->balance($card);
        if ($balance['status'] !== 'blocked') {
            throw new UnexpectedOutcome(Reporter::card($card)." is {$balance['status']}, not blocked. ".self::FRESH_CARDS_HINT);
        }
        $this->report->pass('card status: blocked');
        [$used, $ledger] = [$balance['used_l'], $this->ledgerCount($card)];

        $response = $this->api->post('/transactions', $this->newPayload($card, $this->config->liters));
        $this->expectStatus($response, 403, 'The blocked card');
        $this->check($response->errorCode() === 'card_blocked', 'error code card_blocked');
        $this->report->pass('POST /transactions -> 403 card_blocked');

        $this->expectUnchanged($card, $used, $ledger);
    }

    /** One liter more than the card has left is declined (403 quota_exceeded, liters) and changes nothing. */
    private function quota(): void
    {
        $card = $this->config->tinyCard;
        $this->report->scenario('quota', 'Over the monthly liter quota of '.Reporter::card($card));

        $balance = $this->balance($card);
        if ($balance['status'] !== 'active' || $balance['monthly_limit_l'] === null || $balance['remaining_l'] === null) {
            throw new UnexpectedOutcome(Reporter::card($card).' must be an active card with a liter limit. '.self::FRESH_CARDS_HINT);
        }
        $liters = Cents::format(Cents::parse($balance['remaining_l']) + 100);
        $this->report->pass("{$balance['remaining_l']} L left of {$balance['monthly_limit_l']} L; asking for {$liters} L");
        $ledger = $this->ledgerCount($card);

        $response = $this->api->post('/transactions', $this->newPayload($card, $liters));
        $this->expectStatus($response, 403, 'The over-quota purchase');
        $this->check($response->errorCode() === 'quota_exceeded' && $response->errorDetail('dimension') === 'liters', 'quota_exceeded on the liter limit');
        $this->report->pass('POST /transactions -> 403 quota_exceeded (liters)');

        $this->expectUnchanged($card, $balance['used_l'], $ledger);
    }

    /**
     * @return array{payload: array<string, mixed>, data: array<string, mixed>}
     */
    private function acceptedPurchase(string $scenario): array
    {
        if ($this->accepted === null) {
            $this->report->note("[{$scenario}] needs an accepted purchase from this run; making one first.");
            $this->success();
        }

        assert($this->accepted !== null);

        return $this->accepted;
    }

    /**
     * A new reference and the current time with the Beirut offset, as a
     * station terminal would send it (two seconds back, so a clock a moment
     * ahead of the server's is not refused as a future event).
     *
     * @return array<string, mixed>
     */
    private function newPayload(string $card, string $liters): array
    {
        return [
            'external_ref' => 'SIM-'.gmdate('YmdHis').'-'.strtoupper(bin2hex(random_bytes(3))),
            'card_no' => $card,
            'product_code' => $this->config->product,
            'liters' => $liters,
            'transacted_at' => (new DateTimeImmutable('-2 seconds', new DateTimeZone('Asia/Beirut')))->format('Y-m-d\TH:i:sP'),
        ];
    }

    /**
     * @return array{status: string, monthly_limit_l: ?string, used_l: string, remaining_l: ?string}
     */
    private function balance(string $card): array
    {
        $response = $this->api->get('/cards/'.rawurlencode($card).'/balance');
        $this->expectStatus($response, 200, 'The balance of '.Reporter::card($card));
        $data = $response->data();

        return [
            'status' => (string) ($data['status'] ?? ''),
            'monthly_limit_l' => isset($data['monthly_limit_l']) ? (string) $data['monthly_limit_l'] : null,
            'used_l' => (string) ($data['used_l'] ?? ''),
            'remaining_l' => isset($data['remaining_l']) ? (string) $data['remaining_l'] : null,
        ];
    }

    /** This station's purchases on the card in the current Beirut month (the list's default range). */
    private function ledgerCount(string $card): int
    {
        $response = $this->api->get('/transactions', ['card' => $card, 'per_page' => 1]);
        $this->expectStatus($response, 200, 'The ledger lookup');
        $total = $response->json()['meta']['total'] ?? null;

        if (! is_int($total)) {
            throw new UnexpectedOutcome('The ledger lookup returned no meta.total.');
        }

        return $total;
    }

    private function expectUnchanged(string $card, string $used, int $ledger): void
    {
        $now = $this->balance($card)['used_l'];
        $this->check($now === $used, "usage unchanged (still {$used} L)");
        $this->check($this->ledgerCount($card) === $ledger, "ledger unchanged (still {$ledger})");
        $this->report->pass("no charge: usage still {$used} L, ledger still {$ledger}");
    }

    private function expectLedger(string $card, int $expected, string $what): void
    {
        $count = $this->ledgerCount($card);
        $this->check($count === $expected, "{$what}: expected {$expected}, got {$count}");
        $this->report->pass("{$what}: ".($expected - 1)." -> {$count} (+1)");
    }

    private function expectStatus(ApiResponse $response, int $expected, string $what): void
    {
        if ($response->status === $expected) {
            return;
        }

        $hint = $response->errorCode() === 'quota_exceeded' || $response->errorCode() === 'card_blocked' ? ' '.self::FRESH_CARDS_HINT : '';

        throw new UnexpectedOutcome("{$what}: expected HTTP {$expected}, got {$response->describe()}.{$hint}");
    }

    private function check(bool $condition, string $what): void
    {
        if (! $condition) {
            throw new UnexpectedOutcome("Check failed: {$what}.");
        }
    }
}
