<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * JSON over HTTP with a bearer token, nothing else: the simulator knows the
 * portal only through its public API. Error statuses are returned, not
 * thrown, so scenarios can assert them.
 */
final class ApiClient
{
    private ?string $token = null;

    public function __construct(private readonly ClientInterface $http) {}

    public static function for(Config $config): self
    {
        return new self(new Client([
            'base_uri' => $config->baseUrl.'/',
            'timeout' => $config->timeoutSeconds,
            'connect_timeout' => min(5, $config->timeoutSeconds),
            'http_errors' => false,
            'allow_redirects' => false,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'fleetfuel-pos-simulator/1.0',
            ],
        ]));
    }

    public function useToken(?string $token): void
    {
        $this->token = $token;
    }

    /**
     * @param  array<string, string|int>  $query
     */
    public function get(string $path, array $query = []): ApiResponse
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function post(string $path, array $body): ApiResponse
    {
        return $this->send('POST', $path, ['json' => $body]);
    }

    public function delete(string $path): ApiResponse
    {
        return $this->send('DELETE', $path, []);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function send(string $method, string $path, array $options): ApiResponse
    {
        if ($this->token !== null) {
            $options['headers'] = ['Authorization' => 'Bearer '.$this->token];
        }

        try {
            return ApiResponse::from($this->http->request($method, ltrim($path, '/'), $options));
        } catch (GuzzleException $e) {
            throw new UnexpectedOutcome("Could not reach the API for {$method} {$path}: {$e->getMessage()}");
        }
    }
}
