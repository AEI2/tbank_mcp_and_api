<?php

declare(strict_types=1);

namespace Tbank\Invest;

final class Money
{
    private const int NANO = 1_000_000_000;

    /** @param array<string, mixed>|int|float|string|null $value */
    public static function toFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }
        if (!is_array($value) || (!array_key_exists('units', $value) && !array_key_exists('nano', $value))) {
            return null;
        }

        $units = (int) ($value['units'] ?? 0);
        $nano = (int) ($value['nano'] ?? 0);
        $sign = ($units < 0 || $nano < 0) ? -1.0 : 1.0;

        return $sign * (abs($units) + abs($nano) / self::NANO);
    }

    /** @return array{units: string, nano: int} */
    public static function fromNumber(int|float|string $value): array
    {
        $scaled = (int) round(((float) $value) * self::NANO);
        $units = intdiv($scaled, self::NANO);
        $nano = $scaled % self::NANO;

        return ['units' => (string) $units, 'nano' => $nano];
    }

    /** @param array<string, mixed> $value */
    public static function isQuotationLike(array $value): bool
    {
        if (!array_key_exists('units', $value)) {
            return false;
        }
        $extra = array_diff(array_keys($value), ['units', 'nano', 'currency', 'value']);

        return $extra === [];
    }
}
