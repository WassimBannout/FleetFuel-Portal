<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The shared API error envelope and request IDs (docs/05-API-CONTRACT.md):
 * {"error": {"code", "message", "details", "request_id"}}.
 */
class ErrorResponsesTest extends TestCase
{
    use RefreshDatabase;

    public function test_malformed_json_or_a_non_object_body_is_a_400(): void
    {
        foreach (['{"email": ', '"just a string"', '42'] as $body) {
            $this->call('POST', '/api/v1/auth/token', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
            ], $body)
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'malformed_json');
        }
    }

    public function test_unknown_routes_and_methods_use_the_envelope(): void
    {
        $this->getJson('/api/v1/nothing-here')->assertNotFound()->assertJsonPath('error.code', 'not_found');

        // Even without an Accept header, the API answers in JSON.
        $this->get('/api/v1/nothing-here')->assertNotFound()->assertJsonPath('error.code', 'not_found');

        $this->getJson('/api/v1/auth/token')->assertStatus(405)->assertJsonPath('error.code', 'method_not_allowed');
    }

    public function test_an_unexpected_error_is_a_generic_500_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $log = Log::spy();

        Route::middleware('api')->get('/api/v1/testing/explode', function (): never {
            throw new RuntimeException('Internal detail ZX-9 in /var/www/html/app/Secret.php');
        });

        $response = $this->getJson('/api/v1/testing/explode')->assertStatus(500);

        $this->assertSame(['error'], array_keys($response->json()));
        $this->assertSame(['code', 'message', 'details', 'request_id'], array_keys($response->json('error')));
        $response->assertJsonPath('error.code', 'internal_error')
            ->assertJsonPath('error.message', 'An unexpected error occurred.');

        foreach (['ZX-9', 'Secret.php', 'trace', 'RuntimeException'] as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getContent());
        }

        // The details go to the server log instead.
        $log->shouldHaveReceived('error')
            ->withArgs(fn (string $message): bool => str_contains($message, 'Internal detail ZX-9'))
            ->once();
    }

    public function test_the_envelope_carries_the_same_request_id_as_the_header(): void
    {
        $response = $this->deleteJson('/api/v1/auth/token')->assertUnauthorized();

        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('error.request_id'));
        $this->assertStringContainsString('"details":{}', (string) $response->getContent());
    }

    public function test_every_response_gets_a_fresh_server_generated_request_id(): void
    {
        $first = $this->withHeader('X-Request-Id', 'client-chosen-id')->get('/')->assertOk();
        $second = $this->get('/')->assertOk();

        $firstId = (string) $first->headers->get('X-Request-Id');
        $secondId = (string) $second->headers->get('X-Request-Id');

        $this->assertTrue(Str::isUuid($firstId));
        $this->assertTrue(Str::isUuid($secondId));
        $this->assertNotSame($firstId, $secondId);
    }

    public function test_web_pages_keep_their_html_error_pages(): void
    {
        $response = $this->get('/no-such-page')->assertNotFound();

        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
    }
}
