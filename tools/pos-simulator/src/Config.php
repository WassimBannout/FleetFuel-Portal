<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

/**
 * Settings from environment variables only. Nothing is read from files, so
 * a token or password can never end up committed next to the code.
 */
final class Config
{
    public const DEFAULT_BASE_URL = 'http://localhost:8080/api/v1';

    private function __construct(
        public readonly string $baseUrl,
        public readonly ?string $token,
        public readonly ?string $email,
        public readonly ?string $password,
        public readonly string $card,
        public readonly string $blockedCard,
        public readonly string $tinyCard,
        public readonly string $product,
        public readonly string $liters,
        public readonly int $timeoutSeconds,
    ) {}

    /**
     * @param  array<string, string>  $env
     *
     * @throws ConfigurationError
     */
    public static function fromEnvironment(array $env): self
    {
        $read = static fn (string $name, ?string $default = null): ?string => ($value = trim($env[$name] ?? '')) === '' ? $default : $value;

        $baseUrl = rtrim((string) $read('POS_BASE_URL', self::DEFAULT_BASE_URL), '/');
        if (preg_match('#^https?://[^\s/]+#', $baseUrl) !== 1) {
            throw new ConfigurationError('POS_BASE_URL must be an http:// or https:// URL, such as '.self::DEFAULT_BASE_URL.'.');
        }

        $token = $read('POS_TOKEN');
        $email = $read('POS_EMAIL');
        $password = $env['POS_PASSWORD'] ?? '';
        if ($token === null && ($email === null || $password === '')) {
            throw new ConfigurationError('Set POS_TOKEN, or POS_EMAIL and POS_PASSWORD, in the environment. They are never read from a file.');
        }

        $cards = [];
        foreach (['POS_CARD' => 'FF-ATLAS-001', 'POS_BLOCKED_CARD' => 'FF-ATLAS-BLOCKED', 'POS_TINY_CARD' => 'FF-ATLAS-TINY'] as $name => $default) {
            $cards[$name] = strtoupper((string) $read($name, $default));
            if (preg_match('/^[A-Z0-9-]{1,40}$/', $cards[$name]) !== 1) {
                throw new ConfigurationError("{$name} must be a card number of letters, digits and hyphens (at most 40).");
            }
        }

        $product = strtoupper((string) $read('POS_PRODUCT', 'DIESEL'));
        if (! in_array($product, ['ULP95', 'ULP98', 'DIESEL'], true)) {
            throw new ConfigurationError('POS_PRODUCT must be ULP95, ULP98 or DIESEL.');
        }

        $liters = (string) $read('POS_LITERS', '20.00');
        if (preg_match('/^\d{1,8}(\.\d{1,2})?$/', $liters) !== 1 || Cents::parse($liters) === 0) {
            throw new ConfigurationError('POS_LITERS must be a positive amount with at most 2 decimals, such as 20.00.');
        }

        $timeout = (string) $read('POS_TIMEOUT', '10');
        if (! ctype_digit($timeout) || (int) $timeout < 1 || (int) $timeout > 120) {
            throw new ConfigurationError('POS_TIMEOUT must be a whole number of seconds between 1 and 120.');
        }

        return new self(
            $baseUrl,
            $token,
            $token === null ? $email : null,
            $token === null ? $password : null,
            $cards['POS_CARD'],
            $cards['POS_BLOCKED_CARD'],
            $cards['POS_TINY_CARD'],
            $product,
            Cents::format(Cents::parse($liters)),
            (int) $timeout,
        );
    }
}
