<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks a real response against docs/api/openapi.json (T24): the status
 * code must be documented for the operation, every documented response
 * header must be present, and the JSON body must validate against the
 * documented schema (OpenAPI 3.1 schemas are JSON Schema 2020-12; the
 * opis/json-schema validator follows every $ref into the document).
 */
trait ChecksOpenApiContract
{
    /** Base URI under which the document is registered with the validator. */
    private const OPENAPI_URI = 'https://fleetfuel.test/openapi.json';

    private static ?stdClass $openApiDocument = null;

    private static ?Validator $openApiValidator = null;

    protected static function openApiDocument(): stdClass
    {
        return self::$openApiDocument ??= json_decode(
            (string) file_get_contents(dirname(__DIR__, 2).'/docs/api/openapi.json'), false, 512, JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  TestResponse<Response>  $response
     * @param  string  $path  The OpenAPI path, such as /transactions/{id}
     * @return TestResponse<Response>
     */
    protected function assertMatchesOpenApi(TestResponse $response, string $method, string $path): TestResponse
    {
        $method = strtolower($method);
        $operation = self::openApiDocument()->paths->{$path}->{$method} ?? null;
        $this->assertInstanceOf(stdClass::class, $operation, "{$method} {$path} is not documented in openapi.json.");

        $status = (string) $response->getStatusCode();
        $documented = $operation->responses->{$status} ?? null;
        $this->assertInstanceOf(stdClass::class, $documented, "openapi.json does not document HTTP {$status} for {$method} {$path}: ".$response->getContent());

        foreach ((array) ($documented->headers ?? []) as $header => $definition) {
            if ($definition->required ?? false) {
                $this->assertTrue($response->headers->has($header), "HTTP {$status} of {$method} {$path} must send the {$header} header.");
            }
        }

        if (! isset($documented->content)) {
            $this->assertSame('', (string) $response->getContent(), "HTTP {$status} of {$method} {$path} is documented without a body.");

            return $response;
        }

        // A non-JSON body (the CSV export) is checked by its media type only.
        $mediaTypes = array_keys((array) $documented->content);
        $contentType = (string) $response->headers->get('Content-Type');
        if (! in_array('application/json', $mediaTypes, true)) {
            $this->assertNotEmpty(
                array_filter($mediaTypes, fn (string $type): bool => str_starts_with($contentType, $type)),
                "HTTP {$status} of {$method} {$path} must be one of ".implode(', ', $mediaTypes).", not {$contentType}.",
            );

            return $response;
        }

        $this->assertStringStartsWith('application/json', $contentType);

        $pointer = '#/paths/'.$this->jsonPointerSegment($path)."/{$method}/responses/{$status}/content/application~1json/schema";
        $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        $result = self::openApiValidator()->validate($body, (object) ['$ref' => self::OPENAPI_URI.$pointer]);

        $this->assertTrue($result->isValid(), "HTTP {$status} of {$method} {$path} does not match openapi.json: "
            .json_encode($result->error() === null ? [] : (new ErrorFormatter)->format($result->error()), JSON_UNESCAPED_SLASHES)
            .' Body: '.$response->getContent());

        return $response;
    }

    private static function openApiValidator(): Validator
    {
        if (self::$openApiValidator === null) {
            $validator = new Validator;
            $validator->setMaxErrors(10);
            // Validation must never write schema defaults into the data it checks.
            $validator->parser()->setOption('allowDefaults', false);
            $validator->resolver()?->registerRaw(self::openApiDocument(), self::OPENAPI_URI);
            self::$openApiValidator = $validator;
        }

        return self::$openApiValidator;
    }

    /** "/transactions/{id}" becomes "~1transactions~1%7Bid%7D" (RFC 6901 inside a URI fragment). */
    private function jsonPointerSegment(string $path): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $path));
    }
}
