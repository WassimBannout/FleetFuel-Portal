<?php

namespace App\Services;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Exceptions\BusinessRuleViolation;
use App\Models\CardMonthlyUsage;
use App\Models\Company;
use App\Models\Driver;
use App\Models\FuelCard;
use App\Models\Product;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\BusinessMonth;
use App\Support\CardBalance;
use App\Support\Decimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every change to a fuel card: issuing, assignment, quotas and status.
 *
 * Changes to an existing card run in one database transaction that first
 * locks the card row (SELECT ... FOR UPDATE). POS ingestion (M05) takes the
 * same lock before it checks the quota, so a quota edit or a block and a
 * purchase can never interleave. Each change writes one audit row in the
 * same transaction. Company ownership is never changed.
 */
class FuelCardService
{
    private const CARD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Issue a card to a company. The caller finds the vehicle, driver and
     * product through tenant-scoped queries; they are checked again here.
     */
    public function create(
        Company $company,
        ?Vehicle $vehicle,
        ?Driver $driver,
        ?Product $product,
        ?string $limitL,
        ?string $limitUsd,
        User $actor,
    ): FuelCard {
        $this->ensureCompanyActive($company);
        $this->ensureAssignable($company, $vehicle, $driver, $product);

        return DB::transaction(function () use ($company, $vehicle, $driver, $product, $limitL, $limitUsd, $actor): FuelCard {
            $card = new FuelCard;
            $card->forceFill([
                'company_id' => $company->id,
                'vehicle_id' => $vehicle?->id,
                'driver_id' => $driver?->id,
                'allowed_product_id' => $product?->id,
                'card_no' => $this->newCardNumber(),
                'monthly_limit_l' => Decimal::normalize($limitL),
                'monthly_limit_usd' => Decimal::normalize($limitUsd),
                'status' => CardStatus::Active,
            ])->save();

            $this->audit->record('card.created', $card, $actor, $company->id, null,
                $this->assignment($card) + $this->limits($card) + ['status' => CardStatus::Active->value]);

            return $card;
        });
    }

    /**
     * Change the vehicle, driver or product restriction. Allowed only until
     * the card's first accepted purchase.
     */
    public function updateAssignment(FuelCard $card, ?Vehicle $vehicle, ?Driver $driver, ?Product $product, User $actor): FuelCard
    {
        return DB::transaction(function () use ($card, $vehicle, $driver, $product, $actor): FuelCard {
            $card = $this->lock($card);
            $this->ensureNotArchived($card);
            $company = Company::query()->findOrFail($card->company_id);
            $this->ensureCompanyActive($company);
            $this->ensureAssignable($company, $vehicle, $driver, $product, $card);

            $old = $this->assignment($card);
            $card->forceFill([
                'vehicle_id' => $vehicle?->id,
                'driver_id' => $driver?->id,
                'allowed_product_id' => $product?->id,
            ]);

            if (! $card->isDirty()) {
                return $card;
            }

            // Checked while holding the card lock, so no purchase can be
            // accepted between this check and the update.
            if ($card->transactions()->exists()) {
                throw BusinessRuleViolation::assignmentLocked();
            }

            $card->save();
            $this->audit->record('card.assignment_changed', $card, $actor, $card->company_id, $old, $this->assignment($card));

            return $card;
        });
    }

    /**
     * Set the monthly liter and USD limits (null = unlimited). Lowering a
     * limit below this month's usage is allowed but makes the card over
     * quota. The web screen asks for confirmation first ($allowBelowUsage =
     * false until the user ticks the box).
     */
    public function updateLimits(FuelCard $card, ?string $limitL, ?string $limitUsd, User $actor, bool $allowBelowUsage = true): FuelCard
    {
        return DB::transaction(function () use ($card, $limitL, $limitUsd, $actor, $allowBelowUsage): FuelCard {
            $card = $this->lock($card);
            $this->ensureNotArchived($card);
            $this->ensureCompanyActive(Company::query()->findOrFail($card->company_id));

            $old = $this->limits($card);
            $card->forceFill([
                'monthly_limit_l' => Decimal::normalize($limitL),
                'monthly_limit_usd' => Decimal::normalize($limitUsd),
            ]);

            if (! $card->isDirty()) {
                return $card;
            }

            // Usage only changes under the card lock we hold, so this is exact.
            $balance = $this->balance($card);
            $belowUsage = ($card->isDirty('monthly_limit_l') && $this->isBelow($card->monthly_limit_l, $balance->usedL))
                || ($card->isDirty('monthly_limit_usd') && $this->isBelow($card->monthly_limit_usd, $balance->usedUsd));

            if ($belowUsage && ! $allowBelowUsage) {
                throw BusinessRuleViolation::limitBelowUsage($balance->usedL, $balance->usedUsd);
            }

            $card->save();
            $this->audit->record('card.limits_changed', $card, $actor, $card->company_id, $old,
                $this->limits($card) + ['below_current_usage' => $belowUsage]);

            return $card;
        });
    }

    /**
     * Block, unblock or archive. Archived is final. Unblocking needs an
     * active company; blocking and archiving are always allowed.
     */
    public function changeStatus(FuelCard $card, CardStatus $status, User $actor): FuelCard
    {
        return DB::transaction(function () use ($card, $status, $actor): FuelCard {
            $card = $this->lock($card);
            $this->ensureNotArchived($card);

            if ($card->status === $status) {
                return $card;
            }

            if ($status === CardStatus::Active) {
                $this->ensureCompanyActive(Company::query()->findOrFail($card->company_id));
            }

            $old = $card->status;
            $card->forceFill(['status' => $status])->save();

            $this->audit->record('card.status_changed', $card, $actor, $card->company_id,
                ['status' => $old->value],
                ['status' => $status->value],
            );

            return $card;
        });
    }

    /** Current limits against usage in the Beirut month containing $at (default: now). */
    public function balance(FuelCard $card, ?CarbonInterface $at = null): CardBalance
    {
        $month = BusinessMonth::for($at ?? CarbonImmutable::now());

        $usage = CardMonthlyUsage::query()
            ->where('fuel_card_id', $card->id)
            ->where('month_start', $month)
            ->first();

        return CardBalance::for($card, $usage, $month);
    }

    private function lock(FuelCard $card): FuelCard
    {
        return FuelCard::query()->lockForUpdate()->findOrFail($card->id);
    }

    private function ensureNotArchived(FuelCard $card): void
    {
        if ($card->status === CardStatus::Archived) {
            throw BusinessRuleViolation::cardArchived();
        }
    }

    private function ensureCompanyActive(Company $company): void
    {
        if ($company->status !== CompanyStatus::Active) {
            throw BusinessRuleViolation::companyInactive();
        }
    }

    /**
     * Same-company and active checks. Form Requests already enforce them for
     * user input; this backstop (like the composite foreign keys) protects
     * every other caller. An unchanged assignment may keep a record that has
     * since been deactivated.
     */
    private function ensureAssignable(Company $company, ?Vehicle $vehicle, ?Driver $driver, ?Product $product, ?FuelCard $current = null): void
    {
        $errors = [];

        if ($vehicle !== null && ($vehicle->company_id !== $company->id
            || (! $vehicle->is_active && $current?->vehicle_id !== $vehicle->id))) {
            $errors['vehicle_id'] = 'Choose an active vehicle of this company.';
        }

        if ($driver !== null && ($driver->company_id !== $company->id
            || (! $driver->is_active && $current?->driver_id !== $driver->id))) {
            $errors['driver_id'] = 'Choose an active driver of this company.';
        }

        if ($product !== null && ! $product->is_active && $current?->allowed_product_id !== $product->id) {
            $errors['allowed_product_id'] = 'Choose an active product.';
        }

        if ($vehicle !== null && $product !== null && $product->fuel_type !== $vehicle->fuel_type) {
            $errors['allowed_product_id'] = "The vehicle uses {$vehicle->fuel_type->value}, so the card cannot be restricted to {$product->name}.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function isBelow(?string $limit, string $used): bool
    {
        return $limit !== null && Decimal::isLessThan($limit, $used);
    }

    /** A new, unused card number such as FF-7KQ2-M9XD (no 0/O or 1/I look-alikes). */
    private function newCardNumber(): string
    {
        do {
            $number = 'FF-'.$this->randomBlock().'-'.$this->randomBlock();
        } while (FuelCard::query()->where('card_no', $number)->exists());

        return $number;
    }

    private function randomBlock(): string
    {
        $block = '';
        for ($i = 0; $i < 4; $i++) {
            $block .= self::CARD_ALPHABET[random_int(0, strlen(self::CARD_ALPHABET) - 1)];
        }

        return $block;
    }

    /**
     * Audit values: selected fields only. The card number (a card
     * identifier) is not copied into audit rows; auditable_id names the card.
     *
     * @return array<string, int|null>
     */
    private function assignment(FuelCard $card): array
    {
        return [
            'vehicle_id' => $card->vehicle_id,
            'driver_id' => $card->driver_id,
            'allowed_product_id' => $card->allowed_product_id,
        ];
    }

    /** @return array<string, string|null> */
    private function limits(FuelCard $card): array
    {
        return [
            'monthly_limit_l' => $card->monthly_limit_l,
            'monthly_limit_usd' => $card->monthly_limit_usd,
        ];
    }
}
