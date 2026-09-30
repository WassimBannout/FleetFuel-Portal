<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

use Psr\Http\Message\ResponseInterface;

/** An API answer: status, headers and the decoded JSON body. */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $json
     */
    private function __construct(
        public readonly int $status,
        private readonly ResponseInterface $response,
        private readonly ?array $json,
    ) {}

    public static function from(ResponseInterface $response): self
    {
        $decoded = json_decode((string) $response->getBody(), true);

        return new self($response->getStatusCode(), $response, is_array($decoded) ? $decoded : null);
    }

    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name) ? $this->response->getHeaderLine($name) : null;
    }

    /**
     * The whole decoded body, or null when it was not JSON.
     *
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        return $this->json;
    }

    /**
     * The "data" object of a success response.
     *
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $data = $this->json['data'] ?? null;

        if (! is_array($data)) {
            throw new UnexpectedOutcome("HTTP {$this->status} without a data object.");
        }

        return $data;
    }

    public function errorCode(): ?string
    {
        $code = $this->json['error']['code'] ?? null;

        return is_string($code) ? $code : null;
    }

    public function errorDetail(string $key): mixed
    {
        return $this->json['error']['details'][$key] ?? null;
    }

    /** "HTTP 403 card_blocked: This card is blocked." */
    public function describe(): string
    {
        $message = $this->json['error']['message'] ?? null;

        return "HTTP {$this->status}".($this->errorCode() === null ? '' : ' '.$this->errorCode()).(is_string($message) ? ": {$message}" : '');
    }
}
