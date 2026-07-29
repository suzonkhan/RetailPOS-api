<?php

namespace App\Support;

class Uom
{
    public static function all(): array
    {
        return config('retail360.uoms', []);
    }

    public static function codes(): array
    {
        return array_column(self::all(), 'code');
    }

    public static function find(string $code): ?array
    {
        foreach (self::all() as $uom) {
            if ($uom['code'] === $code) {
                return $uom;
            }
        }

        return null;
    }

    public static function label(string $code): string
    {
        return self::find($code)['label'] ?? $code;
    }

    public static function isFractional(string $code): bool
    {
        return (bool) (self::find($code)['fractional'] ?? false);
    }
}
