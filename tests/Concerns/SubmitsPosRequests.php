<?php

namespace Tests\Concerns;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * POS API helpers: a real Sanctum token with the operator's role abilities
 * and a valid purchase payload that tests change field by field.
 */
trait SubmitsPosRequests
{
    protected function posToken(User $operator): string
    {
        return $operator->createToken('phpunit-pos', $operator->role->tokenAbilities(), CarbonImmutable::now()->addDay())->plainTextToken;
    }

    /**
     * A 20 L diesel purchase one hour ago, sent with the Beirut offset.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function posPayload(string $cardNo, array $overrides = []): array
    {
        return array_merge([
            'external_ref' => 'POS-T-0001',
            'card_no' => $cardNo,
            'product_code' => 'DIESEL',
            'liters' => '20.00',
            'transacted_at' => CarbonImmutable::now()->subHour()->setTimezone('Asia/Beirut')->format('Y-m-d\TH:i:sP'),
            'odometer_km' => 45000,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return TestResponse<Response>
     */
    protected function submitPurchase(string $token, array $payload): TestResponse
    {
        $this->startNewRequestCycle();

        return $this->withToken($token)->postJson('/api/v1/transactions', $payload);
    }
}
