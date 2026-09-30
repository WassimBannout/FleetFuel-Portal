<?php

namespace Tests\Feature\Api;

use App\Models\FuelTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;
use Tests\Concerns\BuildsLedgerFixtures;
use Tests\Concerns\CallsApi;
use Tests\Concerns\ChecksOpenApiContract;
use Tests\Concerns\SignsInDemoAccounts;
use Tests\Concerns\SubmitsPosRequests;
use Tests\TestCase;

/**
 * T24: docs/api/openapi.json is a valid OpenAPI 3.1 document, it lists
 * exactly the routes that exist (with their abilities and roles), and real
 * responses of the M02/M05 endpoints match it, errors included. The M06
 * endpoints are checked the same way in their own tests.
 */
class OpenApiContractTest extends TestCase
{
    use BuildsLedgerFixtures;
    use CallsApi;
    use ChecksOpenApiContract;
    use RefreshDatabase;
    use SignsInDemoAccounts;
    use SubmitsPosRequests;

    private const META_SCHEMA_ID = 'https://spec.openapis.org/oas/3.1/schema/2022-10-07';

    private const HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public function test_the_document_is_valid_against_the_official_openapi_3_1_schema(): void
    {
        $this->assertSame([], $this->metaSchemaErrors(self::openApiDocument()));
    }

    public function test_the_schema_check_would_catch_a_broken_document(): void
    {
        $broken = json_decode((string) json_encode(self::openApiDocument()), false, 512, JSON_THROW_ON_ERROR);
        unset($broken->info->version);
        $broken->paths->{'/transactions'}->post->responses->{'201'}->headers->Location->bogus = true;

        $errors = $this->metaSchemaErrors($broken);

        $this->assertArrayHasKey('/info', $errors);
        $this->assertArrayHasKey('/paths/~1transactions/post/responses/201/headers/Location', $errors);
        $this->assertCount(2, $errors);
    }

    public function test_every_required_property_of_every_schema_is_defined(): void
    {
        foreach ((array) self::openApiDocument()->components->schemas as $name => $schema) {
            $properties = array_keys((array) ($schema->properties ?? []));

            $this->assertSame([], array_values(array_diff($schema->required ?? [], $properties)), "Schema {$name} requires undefined properties.");
        }
    }

    /**
     * Route parity: every implemented operation is routed with the documented
     * token abilities and roles, every route is documented, and planned
     * operations (deliveries, reports) are not routed yet.
     */
    public function test_routes_match_the_documented_operations_abilities_and_roles(): void
    {
        $implemented = [];
        $planned = [];

        foreach ((array) self::openApiDocument()->paths as $path => $item) {
            foreach ((array) $item as $method => $operation) {
                if (! in_array($method, self::HTTP_METHODS, true)) {
                    continue;
                }

                $key = strtoupper($method).' '.$this->shape($path);
                $status = $operation->{'x-status'} ?? null;
                $this->assertContains($status, ['implemented', 'planned'], "{$key} needs x-status.");

                if ($status === 'planned') {
                    $planned[] = $key;

                    continue;
                }

                $implemented[$key] = [
                    'public' => ($operation->security ?? null) === [],
                    'abilities' => $this->sorted($operation->{'x-abilities'}),
                    'roles' => $this->sorted($operation->{'x-roles'} ?? []),
                ];
            }
        }

        $routed = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                    $routed[$method.' '.$this->shape(substr($route->uri(), strlen('api/v1')))] = $this->guards($route);
                }
            }
        }

        ksort($implemented);
        ksort($routed);
        $this->assertSame($implemented, $routed);

        sort($planned);
        $this->assertSame([
            'GET /delivery-orders',
            'GET /delivery-orders/{}',
            'GET /exports/transactions.csv',
            'GET /reports/consumption',
            'PATCH /delivery-orders/{}/status',
            'POST /delivery-orders',
        ], $planned);
    }

    public function test_token_responses_match_the_contract(): void
    {
        $this->seedDemo();
        $credentials = ['email' => 'operator.beirut@fleetfuel.test', 'password' => self::DEMO_PASSWORD, 'device_name' => 'contract'];

        $issued = $this->assertMatchesOpenApi($this->postJson('/api/v1/auth/token', $credentials), 'POST', '/auth/token')->assertCreated();
        $this->assertMatchesOpenApi($this->postJson('/api/v1/auth/token', ['password' => 'wrong'] + $credentials), 'POST', '/auth/token')->assertUnauthorized();
        $this->assertMatchesOpenApi($this->postJson('/api/v1/auth/token', ['role' => 'admin'] + $credentials), 'POST', '/auth/token')->assertUnprocessable();
        $this->assertMatchesOpenApi($this->call('POST', '/api/v1/auth/token', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"email": '), 'POST', '/auth/token')
            ->assertStatus(400);

        // Malformed JSON is refused before the limiter runs; the other three
        // requests counted. The fifth per email and IP is served, the sixth is 429.
        $this->postJson('/api/v1/auth/token', $credentials)->assertCreated();
        $this->postJson('/api/v1/auth/token', $credentials)->assertCreated();
        $limited = $this->assertMatchesOpenApi($this->postJson('/api/v1/auth/token', $credentials), 'POST', '/auth/token')->assertTooManyRequests();
        $this->assertGreaterThan(0, (int) $limited->headers->get('Retry-After'));

        $token = (string) $issued->json('data.token');
        $this->startNewRequestCycle();
        $this->assertMatchesOpenApi($this->withToken($token)->deleteJson('/api/v1/auth/token'), 'DELETE', '/auth/token')->assertNoContent();
        $this->startNewRequestCycle();
        $this->assertMatchesOpenApi($this->withToken($token)->deleteJson('/api/v1/auth/token'), 'DELETE', '/auth/token')->assertUnauthorized();
    }

    public function test_pos_responses_match_the_contract_including_declines(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
        $token = $this->posToken($this->operator());
        $payload = $this->posPayload('FF-ATLAS-001', ['external_ref' => 'CONTRACT-1']);

        $created = $this->assertMatchesOpenApi($this->submitPurchase($token, $payload), 'POST', '/transactions')->assertCreated();
        $this->assertMatchesOpenApi($this->submitPurchase($token, $payload), 'POST', '/transactions')->assertOk();
        $this->assertMatchesOpenApi($this->submitPurchase($token, ['liters' => '21.00'] + $payload), 'POST', '/transactions')->assertConflict();
        $this->assertMatchesOpenApi($this->submitPurchase($token, $this->posPayload('FF-ATLAS-BLOCKED', ['external_ref' => 'CONTRACT-2'])), 'POST', '/transactions')
            ->assertForbidden()->assertJsonPath('error.code', 'card_blocked');
        $this->assertMatchesOpenApi($this->submitPurchase($token, $this->posPayload('FF-ATLAS-TINY', ['external_ref' => 'CONTRACT-3', 'liters' => '6.00'])), 'POST', '/transactions')
            ->assertForbidden()->assertJsonPath('error.details.dimension', 'liters');
        $this->assertMatchesOpenApi($this->submitPurchase($token, $this->posPayload('FF-NOPE-404', ['external_ref' => 'CONTRACT-4'])), 'POST', '/transactions')->assertNotFound();
        $this->assertMatchesOpenApi($this->submitPurchase($token, ['station_id' => 1] + $payload), 'POST', '/transactions')->assertUnprocessable();
        $this->assertMatchesOpenApi($this->submitPurchase($this->apiToken($this->atlasManager()), $payload), 'POST', '/transactions')->assertForbidden();

        $this->startNewRequestCycle();
        $this->assertMatchesOpenApi($this->withoutToken()->postJson('/api/v1/transactions', $payload), 'POST', '/transactions')->assertUnauthorized();

        $this->assertSame('/api/v1/transactions/'.$created->json('data.id'), $created->headers->get('Location'));

        // Days later the fixture rate has expired (and so has the token, so a
        // new one is issued): a new purchase is 503, never converted at a guessed rate.
        $this->travel(10)->days();
        $this->assertMatchesOpenApi($this->submitPurchase($this->posToken($this->operator()), $this->posPayload('FF-ATLAS-001', ['external_ref' => 'CONTRACT-5'])), 'POST', '/transactions')
            ->assertStatus(503)->assertJsonPath('error.code', 'rate_unavailable');
    }

    public function test_ledger_and_balance_responses_match_the_contract(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::FIXTURE_NOW));
        $this->seedDemo();
        $manager = $this->apiToken($this->atlasManager());
        $cedarPurchase = FuelTransaction::query()->where('company_id', $this->cedar()->id)->firstOrFail();

        $list = $this->assertMatchesOpenApi($this->api('GET', '/api/v1/transactions?per_page=5', $manager), 'GET', '/transactions')->assertOk();
        $this->assertMatchesOpenApi($this->api('GET', '/api/v1/transactions?company_id=1', $manager), 'GET', '/transactions')->assertUnprocessable();
        $this->assertMatchesOpenApi($this->api('GET', '/api/v1/transactions', $this->apiToken($this->atlasManager(), ['cards:read'])), 'GET', '/transactions')->assertForbidden();

        $this->assertMatchesOpenApi($this->api('GET', '/api/v1/transactions/'.$list->json('data.0.id'), $manager), 'GET', '/transactions/{id}')->assertOk();
        $this->assertMatchesOpenApi($this->api('GET', '/api/v1/transactions/'.$cedarPurchase->id, $manager), 'GET', '/transactions/{id}')->assertNotFound();

        $this->assertMatchesOpenApi($this->api('GET', '/api/v1/cards/FF-ATLAS-H02/balance', $manager), 'GET', '/cards/{card_no}/balance')->assertOk();
        $this->assertMatchesOpenApi($this->api('GET', '/api/v1/cards/FF-CEDAR-001/balance', $manager), 'GET', '/cards/{card_no}/balance')->assertNotFound();
        $this->assertMatchesOpenApi($this->api('GET', '/api/v1/cards/FF-ATLAS-001/balance?month=2026-01', $manager), 'GET', '/cards/{card_no}/balance')->assertUnprocessable();
    }

    /**
     * Errors from the official schema, keyed by JSON pointer.
     *
     * @return array<string, mixed>
     */
    private function metaSchemaErrors(stdClass $document): array
    {
        // The official schema, stored unmodified. opis/json-schema resolves its
        // "$dynamicRef": "#meta" to the document root instead of the Schema
        // Object definition. In this base schema nothing overrides the
        // dynamic anchor, so the reference means "$ref": "#/$defs/schema",
        // which is substituted here in memory.
        $raw = (string) file_get_contents(base_path('tests/Fixtures/openapi/oas-3.1-schema-2022-10-07.json'));
        $raw = str_replace('"$dynamicRef": "#meta"', '"$ref": "#/$defs/schema"', $raw, $replaced);
        $this->assertSame(4, $replaced);

        $validator = new Validator;
        $validator->setMaxErrors(50);
        $validator->parser()->setOption('allowDefaults', false);
        $validator->resolver()?->registerRaw(json_decode($raw, false, 512, JSON_THROW_ON_ERROR), self::META_SCHEMA_ID);

        $error = $validator->validate($document, self::META_SCHEMA_ID)->error();

        return $error === null ? [] : (new ErrorFormatter)->format($error);
    }

    /**
     * @return array{public: bool, abilities: list<string>, roles: list<string>}
     */
    private function guards(RoutingRoute $route): array
    {
        $middleware = array_filter($route->gatherMiddleware(), 'is_string');
        $abilities = [];
        $roles = [];

        foreach ($middleware as $name) {
            if (str_starts_with($name, 'abilities:')) {
                $abilities = [...$abilities, ...explode(',', substr($name, strlen('abilities:')))];
            } elseif (str_starts_with($name, 'role:')) {
                $roles = [...$roles, ...explode(',', substr($name, strlen('role:')))];
            }
        }

        return [
            'public' => ! in_array('auth:sanctum', $middleware, true),
            'abilities' => $this->sorted($abilities),
            'roles' => $this->sorted($roles),
        ];
    }

    /** "/transactions/{id}" and "/transactions/{transaction}" both become "/transactions/{}". */
    private function shape(string $path): string
    {
        return (string) preg_replace('/\{[^}]+\}/', '{}', $path);
    }

    /**
     * @param  array<int, string>  $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
