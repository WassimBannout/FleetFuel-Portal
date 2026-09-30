<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Real Sanctum tokens and JSON requests for API tests. Each call starts a
 * fresh request cycle, so the guard never reuses the previous caller.
 */
trait CallsApi
{
    /**
     * @param  list<string>|null  $abilities  Defaults to the abilities the role's tokens get.
     */
    protected function apiToken(User $user, ?array $abilities = null): string
    {
        return $user->createToken('phpunit-api', $abilities ?? $user->role->tokenAbilities())->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return TestResponse<Response>
     */
    protected function api(string $method, string $uri, ?string $token, array $body = []): TestResponse
    {
        $this->startNewRequestCycle();

        $client = $token === null ? $this->withoutToken() : $this->withToken($token);

        return $client->json($method, $uri, $body);
    }
}
