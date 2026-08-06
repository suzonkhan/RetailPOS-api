<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Single source of truth for Retail360 wall-clock time (Asia/Dhaka).
 * Sync protocol remains UTC via explicit ->utc() calls elsewhere.
 */
final class AppTimezone
{
    public static function name(): string
    {
        return (string) config('retail360.timezone', config('app.timezone', 'Asia/Dhaka'));
    }

    public static function now(): CarbonInterface
    {
        return now(self::name());
    }

    public static function parse(mixed $value): CarbonInterface
    {
        return Carbon::parse($value, self::name());
    }

    /** Start of a calendar Y-m-d (or Carbon) in app timezone. */
    public static function startOfDay(mixed $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date->copy()->timezone(self::name())->startOfDay();
        }

        return Carbon::parse((string) $date, self::name())->startOfDay();
    }

    /** End of a calendar Y-m-d (or Carbon) in app timezone. */
    public static function endOfDay(mixed $date): CarbonInterface
    {
        if ($date instanceof CarbonInterface) {
            return $date->copy()->timezone(self::name())->endOfDay();
        }

        return Carbon::parse((string) $date, self::name())->endOfDay();
    }

    /** Ensure a Carbon instant is expressed in app timezone. */
    public static function local(mixed $value): CarbonInterface
    {
        return Carbon::parse($value)->timezone(self::name());
    }
}
