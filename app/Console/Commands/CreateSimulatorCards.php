<?php

namespace App\Console\Commands;

use App\Enums\CardStatus;
use App\Enums\CompanyStatus;
use App\Models\Company;
use App\Models\FuelCard;
use App\Models\Product;
use App\Services\AuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fresh, dedicated cards for the POS simulator and Postman scenarios. The
 * seeded simulator cards are consumed by use (quota is per month and a
 * purchase can never be undone), so a rerun needs new cards. This command
 * only adds three cards to the demo company Atlas Logistics; it never
 * resets, deletes or changes existing data.
 */
class CreateSimulatorCards extends Command
{
    protected $signature = 'demo:simulator-cards
        {--tag= : 1 to 8 letters or digits used in the card numbers (default: random)}';

    protected $description = 'Add three fresh POS simulator cards to the demo company (local demo only; nothing else changes)';

    private const COMPANY = 'Atlas Logistics';

    private const TAG_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /** [suffix, liter limit, USD limit, status, simulator variable] */
    private const CARDS = [
        ['MAIN', '100.00', '100.00', CardStatus::Active, 'POS_CARD'],
        ['BLOCKED', '100.00', '100.00', CardStatus::Blocked, 'POS_BLOCKED_CARD'],
        ['TINY', '5.00', '100.00', CardStatus::Active, 'POS_TINY_CARD'],
    ];

    public function handle(AuditService $audit): int
    {
        if (! $this->laravel->environment(['local', 'testing']) || ! config('fleetfuel.demo.enabled')) {
            $this->error('Simulator cards are demo data: they can only be added in the local or testing environment with DEMO_MODE on.');

            return self::FAILURE;
        }

        $tag = $this->tag();
        if ($tag === null) {
            $this->error('--tag must be 1 to 8 letters or digits.');

            return self::FAILURE;
        }

        $company = Company::query()->where('name', self::COMPANY)->where('status', CompanyStatus::Active->value)->first();
        $diesel = Product::query()->where('code', 'DIESEL')->where('is_active', true)->first();
        if ($company === null || $diesel === null) {
            $this->error('The demo company "'.self::COMPANY.'" or the DIESEL product is missing or inactive. Seed the demo first (make setup).');

            return self::FAILURE;
        }

        $numbers = array_map(fn (array $card): string => "FF-SIM-{$tag}-{$card[0]}", self::CARDS);
        if (FuelCard::query()->whereIn('card_no', $numbers)->exists()) {
            $this->error("Cards with the tag {$tag} already exist; choose another --tag. Nothing was changed.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($company, $diesel, $numbers, $audit): void {
            foreach (self::CARDS as $i => [, $limitL, $limitUsd, $status]) {
                $card = FuelCard::query()->forceCreate([
                    'company_id' => $company->id,
                    'vehicle_id' => null,
                    'driver_id' => null,
                    'card_no' => $numbers[$i],
                    'allowed_product_id' => $diesel->id,
                    'monthly_limit_l' => $limitL,
                    'monthly_limit_usd' => $limitUsd,
                    'status' => $status,
                ]);

                // Same audit shape as FuelCardService::create(); no user, as a command did it.
                $audit->record('card.created', $card, null, $company->id, null, [
                    'vehicle_id' => null,
                    'driver_id' => null,
                    'allowed_product_id' => $diesel->id,
                    'monthly_limit_l' => $limitL,
                    'monthly_limit_usd' => $limitUsd,
                    'status' => $status->value,
                ]);
            }
        });

        $this->info('Added three fresh simulator cards to '.self::COMPANY." (tag {$tag}), diesel only:");
        $this->table(['Card', 'Status', 'Monthly limits'], array_map(
            fn (array $card, string $number): array => [$number, $card[3]->value, "{$card[1]} L / {$card[2]} USD"],
            self::CARDS,
            $numbers,
        ));
        $this->line('Point the simulator at them:');
        $this->line('  export '.implode(' ', array_map(fn (array $card, string $number): string => "{$card[4]}={$number}", self::CARDS, $numbers)));
        $this->line('Nothing else was changed.');

        return self::SUCCESS;
    }

    private function tag(): ?string
    {
        $option = $this->option('tag');

        if (is_string($option)) {
            $tag = strtoupper(trim($option));

            return preg_match('/^[A-Z0-9]{1,8}$/', $tag) === 1 ? $tag : null;
        }

        $tag = '';
        for ($i = 0; $i < 4; $i++) {
            $tag .= self::TAG_ALPHABET[random_int(0, strlen(self::TAG_ALPHABET) - 1)];
        }

        return $tag;
    }
}
