<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\IssueTokenRequest;
use App\Services\CredentialVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class TokenController extends Controller
{
    public const LIFETIME_HOURS = 24;

    /**
     * Issue a personal access token. The plaintext token is returned once
     * and only its SHA-256 hash is stored.
     */
    public function store(IssueTokenRequest $request, CredentialVerifier $credentials): JsonResponse
    {
        $email = $request->string('email')->toString();
        $user = $credentials->verify($email, $request->string('password')->toString());

        if ($user === null) {
            event(new Failed('sanctum', null, ['email' => $email]));

            throw new ApiException(401, 'invalid_credentials', (string) __('auth.failed'));
        }

        $expiresAt = CarbonImmutable::now()->addHours(self::LIFETIME_HOURS)->startOfSecond();

        $token = $user->createToken(
            $request->string('device_name')->toString(),
            $user->role->tokenAbilities(),
            $expiresAt,
        );

        return response()->json([
            'data' => [
                'token' => $token->plainTextToken,
                'expires_at' => $expiresAt->utc()->format('Y-m-d\TH:i:s\Z'),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'role' => $user->role->value,
                ],
            ],
        ], 201, ['Cache-Control' => 'no-store']);
    }

    /** Revoke the token that authenticated this request. */
    public function destroy(Request $request): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->noContent();
    }
}
