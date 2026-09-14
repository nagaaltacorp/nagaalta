<?php

namespace App\Support;

class SaleQuantity
{
    public const SCALE = 4;

    /**
     * @return array{0: ?float, 1: ?string}
     */
    public static function parse(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }

        $original = trim((string) $raw);
        $normalized = strtr($original, [
            '¼' => '1/4',
            '½' => '1/2',
            '¾' => '3/4',
            '⅓' => '1/3',
            '⅔' => '2/3',
        ]);

        $quantity = null;
        $label = null;

        if (preg_match('/^(\d+)\s+(\d+)\s*\/\s*(\d+)$/', $normalized, $matches)) {
            $denominator = (int) $matches[3];

            if ($denominator > 0) {
                $quantity = (int) $matches[1] + ((int) $matches[2] / $denominator);
                $label = $original;
            }
        } elseif (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $normalized, $matches)) {
            $denominator = (int) $matches[2];

            if ($denominator > 0) {
                $quantity = (int) $matches[1] / $denominator;
                $label = $original;
            }
        } elseif (is_numeric($normalized)) {
            $quantity = (float) $normalized;
            $label = self::labelFor($quantity);
        }

        if ($quantity === null) {
            return [null, null];
        }

        $quantity = self::round($quantity);

        if ($quantity <= 0) {
            return [null, null];
        }

        return [$quantity, $label];
    }

    public static function round(float $quantity): float
    {
        return round($quantity, self::SCALE);
    }

    public static function isZero(float $quantity): bool
    {
        return abs(self::round($quantity)) < 0.00005;
    }

    public static function isWhole(float $quantity): bool
    {
        return self::isZero($quantity - round($quantity));
    }

    public static function labelFor(float $quantity): ?string
    {
        $map = [
            '0.25' => '1/4',
            '0.5' => '1/2',
            '0.75' => '3/4',
            '0.3333' => '1/3',
            '0.6667' => '2/3',
        ];

        $key = self::display($quantity);

        return $map[$key] ?? null;
    }

    public static function display(float $quantity, ?string $label = null): string
    {
        if (is_string($label) && trim($label) !== '') {
            return trim($label);
        }

        $formatted = number_format(self::round($quantity), self::SCALE, '.', '');
        $formatted = rtrim(rtrim($formatted, '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }
}
