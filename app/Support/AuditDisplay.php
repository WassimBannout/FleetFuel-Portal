<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * How audit rows are shown on the audit screen (docs/06-UI-SPEC.md, "Audit").
 *
 * Services audit explicitly chosen business fields only, so no secret should
 * ever be stored. The screen still redacts as a second line of defense: a
 * field named like a credential is never shown, and card numbers are masked
 * as they are on every other list.
 */
final class AuditDisplay
{
    /** Field names that are never displayed: password, *_token, secret, api_key… */
    private const SECRET_FIELD = '/(^|_)(password|secret|token|api_key)$/i';

    /** Morph-map alias => [label, route of its page or null]. */
    private const ENTITIES = [
        'company' => ['Company', 'companies.edit'],
        'station' => ['Station', 'stations.edit'],
        'product' => ['Product', 'products.edit'],
        'product_price' => ['Product price', null],
        'exchange_rate' => ['Exchange rate', null],
        'vehicle' => ['Vehicle', 'vehicles.edit'],
        'driver' => ['Driver', 'drivers.edit'],
        'fuel_card' => ['Fuel card', 'cards.show'],
        'fuel_transaction' => ['Purchase', 'transactions.show'],
        'delivery_order' => ['Delivery order', 'deliveries.show'],
        'user' => ['User account', null],
    ];

    /**
     * One row per field that appears before or after the change. A side that
     * did not record the field is null (shown as a dash).
     *
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     * @return list<array{field: string, before: ?string, after: ?string}>
     */
    public static function changes(?array $old, ?array $new): array
    {
        $old ??= [];
        $new ??= [];
        $rows = [];

        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $field) {
            $field = (string) $field;
            $rows[] = [
                'field' => $field,
                'before' => array_key_exists($field, $old) ? self::value($field, $old[$field]) : null,
                'after' => array_key_exists($field, $new) ? self::value($field, $new[$field]) : null,
            ];
        }

        return $rows;
    }

    public static function value(string $field, mixed $value): string
    {
        if (preg_match(self::SECRET_FIELD, $field) === 1) {
            return '[redacted]';
        }

        return match (true) {
            $value === null => 'none',
            is_bool($value) => $value ? 'yes' : 'no',
            is_string($value) && str_ends_with($field, 'card_no') => Redact::cardNumber($value),
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        };
    }

    /** "card.limits_changed" becomes "Card limits changed". */
    public static function action(string $action): string
    {
        return Str::ucfirst(str_replace(['.', '_'], ' ', $action));
    }

    public static function entityLabel(string $alias): string
    {
        return self::ENTITIES[$alias][0] ?? Str::ucfirst(str_replace('_', ' ', $alias));
    }

    /** The record's page, when the screen has one. */
    public static function entityUrl(string $alias, int $id): ?string
    {
        $route = self::ENTITIES[$alias][1] ?? null;

        return $route === null ? null : route($route, $id);
    }

    /**
     * @return array<string, string> alias => label, for the entity filter
     */
    public static function entityOptions(): array
    {
        return array_map(fn (array $entity): string => $entity[0], self::ENTITIES);
    }
}
