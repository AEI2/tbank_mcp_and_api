<?php

declare(strict_types=1);

namespace Tbank\Invest;

final class Normalize
{
    public static function dropNulls(mixed $value): mixed
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $out = [];
            foreach ($value as $key => $item) {
                if ($item === null) {
                    continue;
                }
                $converted = self::dropNulls($item);
                if ($isList) {
                    $out[] = $converted;
                } else {
                    $out[$key] = $converted;
                }
            }

            return $out;
        }

        return $value;
    }

    public static function values(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::values(...), $value);
            }
            $converted = [];
            foreach ($value as $key => $item) {
                $converted[$key] = self::values($item);
            }
            if (Money::isQuotationLike($value)) {
                $numeric = Money::toFloat($value);
                if ($numeric !== null) {
                    $converted['value'] = $numeric;
                }
            }

            return $converted;
        }

        return $value;
    }
}
