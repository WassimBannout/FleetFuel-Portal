<?php

namespace App\Exceptions;

use App\Support\RequestId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every exception on /api/* as the documented error envelope:
 *
 *     {"error": {"code", "message", "details", "request_id"}}
 *
 * Unexpected faults become a generic 500 with no message, file or trace,
 * even when APP_DEBUG is on; the details go to the server log only.
 * Headers such as Retry-After are preserved.
 */
class ApiErrorRenderer
{
    public function __invoke(Throwable $e, Request $request): ?Response
    {
        if (! $request->is('api/*')) {
            return null;
        }

        if ($e instanceof HttpResponseException) {
            return $e->getResponse();
        }

        [$status, $code, $message, $details, $headers] = $this->describe($e);

        return new JsonResponse([
            'error' => [
                'code' => $code,
                'message' => $message,
                // Always a JSON object, even when empty.
                'details' => $details === [] ? new stdClass : $details,
                'request_id' => RequestId::current() ?? (string) Str::uuid(),
            ],
        ], $status, $headers);
    }

    /**
     * @return array{int, string, string, array<string, mixed>, array<string, mixed>}
     */
    private function describe(Throwable $e): array
    {
        if ($e instanceof ApiException) {
            return [$e->status, $e->errorCode, $e->getMessage(), $e->details, $e->headers];
        }

        if ($e instanceof ValidationException) {
            return [422, 'validation_failed', 'The given data was invalid.', $e->errors(), []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'unauthenticated', 'Authentication is required.', [], []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            [$code, $message] = match ($status) {
                400 => ['bad_request', 'The request could not be understood.'],
                401 => ['unauthenticated', 'Authentication is required.'],
                403 => ['forbidden', 'You are not allowed to perform this action.'],
                404 => ['not_found', 'The requested resource was not found.'],
                405 => ['method_not_allowed', 'This method is not allowed for this resource.'],
                429 => ['rate_limited', 'Too many requests. Retry later.'],
                503 => ['temporarily_unavailable', 'The service is temporarily unavailable.'],
                default => $status < 500
                    ? ['http_error', 'The request could not be processed.']
                    : ['internal_error', 'An unexpected error occurred.'],
            };

            return [$status, $code, $message, [], $e->getHeaders()];
        }

        return [500, 'internal_error', 'An unexpected error occurred.', [], []];
    }
}
