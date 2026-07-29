<?php

namespace App\Support;

class MobileNormalizer
{
    public static function normalize(string $mobile): string
    {
        $digits = preg_replace('/\D/', '', $mobile);

        if (str_starts_with($digits, '880')) {
            return $digits;
        }

        if (str_starts_with($digits, '01')) {
            return '88'.$digits;
        }

        if (str_starts_with($digits, '1') && strlen($digits) === 10) {
            return '880'.$digits;
        }

        return $digits;
    }

    public static function isValidBangladeshMobile(string $normalized): bool
    {
        return (bool) preg_match('/^8801\d{9}$/', $normalized);
    }
}
