<?php

namespace App\Services;

use App\Enums\RateMode;
use App\Enums\RateSource;
use App\Exceptions\PriceUnavailable;
use App\Exceptions\RateUnavailable;
use App\Models\ExchangeRate;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Rules\DecimalString;
use App\Support\Decimal;
use App\Support\FuelAmounts;
use App\Support\PriceQuote;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Chooses the stored price and exchange rate that apply at an instant T,
 * and calculates a purchase's amounts from them. It only reads the
 * database: no provider is ever called here (docs/04-BUSINESS-RULES.md).
 *
 * - Price: the latest price with effective_from <= T.
 * - Rate: among rows with effective_at <= T < expires_at, the latest manual
 *   override; otherwise the latest provider observation (live mode) or
 *   fixture observation (fixture mode). Live mode never uses fixtures.
 *
 * Because T is the event time, a later price or rate never changes what an
 * earlier purchase resolves to.
 */
class PriceResolver
{
    public function findPrice(Product $product, CarbonInterface $at): ?ProductPrice
    {
        return ProductPrice::query()
            ->where('product_id', $product->id)
            ->where('effective_from', '<=', self::instant($at))
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * @throws PriceUnavailable
     */
    public function priceAt(Product $product, CarbonInterface $at): ProductPrice
    {
        return $this->findPrice($product, $at) ?? throw PriceUnavailable::for($product);
    }

    /**
     * @param  RateMode|null  $mode  Defaults to EXCHANGE_RATE_MODE. The demo
     *                               seed passes Fixture: its history is synthetic.
     */
    public function findRate(CarbonInterface $at, ?RateMode $mode = null): ?ExchangeRate
    {
        $mode ??= RateMode::current();

        foreach ([RateSource::Manual, $mode->source()] as $source) {
            $rate = ExchangeRate::query()
                ->usdLbp()
                ->where('source', $source)
                ->eligibleAt(self::instant($at))
                ->orderByDesc('effective_at')
                ->first();

            if ($rate !== null) {
                return $rate;
            }
        }

        return null;
    }

    /**
     * @throws RateUnavailable
     */
    public function rateAt(CarbonInterface $at, ?RateMode $mode = null): ExchangeRate
    {
        return $this->findRate($at, $mode) ?? throw RateUnavailable::make();
    }

    /**
     * Amounts for buying $liters of $product at $at:
     * amount_lbp = round_half_up(liters × price, 2), then
     * amount_usd = round_half_up(amount_lbp ÷ rate, 2).
     *
     * @throws ValidationException when liters is malformed or an amount would overflow its column
     * @throws PriceUnavailable
     * @throws RateUnavailable
     */
    public function quote(Product $product, string $liters, CarbonInterface $at, ?RateMode $mode = null): PriceQuote
    {
        // Backstop for callers; requests validate the same format first.
        Validator::make(['liters' => $liters], ['liters' => [new DecimalString(8, 2, allowZero: false)]])->validate();
        $liters = (string) Decimal::normalize($liters);

        $price = $this->priceAt($product, $at);
        $rate = $this->rateAt($at, $mode);

        $amountLbp = FuelAmounts::amountLbp($liters, $price->price_lbp);
        $amountUsd = FuelAmounts::amountUsd($amountLbp, $rate->rate);

        // A product of individually valid values can still overflow a column
        // (fuel_transactions.amount_lbp DECIMAL(20,2), amount_usd DECIMAL(18,2)).
        if (! Decimal::fits($amountLbp, 20, 2) || ! Decimal::fits($amountUsd, 18, 2)) {
            throw ValidationException::withMessages(['liters' => 'This purchase amount is too large to record.']);
        }

        return new PriceQuote($price, $rate, $liters, $amountLbp, $amountUsd);
    }

    /** Stored instants are UTC with whole seconds. */
    private static function instant(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->utc()->startOfSecond();
    }
}
