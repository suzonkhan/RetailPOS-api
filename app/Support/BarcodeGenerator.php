<?php

namespace App\Support;

use App\Models\Product;
use App\Models\ProductVariant;
use RuntimeException;

class BarcodeGenerator
{
    /**
     * Generate a unique 13-digit in-store barcode for the tenant.
     * Uses prefix 2 (internal / store-assigned) so it won't collide with
     * manufacturer EANs that typically start with other digits.
     */
    public function uniqueForTenant(int $tenantId, int $storeId): string
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $code = $this->makeCandidate($storeId);

            if (! $this->existsForTenant($tenantId, $code)) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique barcode.');
    }

    public function existsForTenant(int $tenantId, string $barcode): bool
    {
        if (Product::query()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $barcode)
            ->exists()) {
            return true;
        }

        return ProductVariant::query()
            ->where('tenant_id', $tenantId)
            ->where('barcode', $barcode)
            ->exists();
    }

    private function makeCandidate(int $storeId): string
    {
        // 2 + 4-digit store fragment + 7 random digits = 12 digits, then check digit.
        $body = sprintf(
            '2%04d%07d',
            $storeId % 10000,
            random_int(0, 9_999_999),
        );

        return $body.$this->ean13CheckDigit($body);
    }

    private function ean13CheckDigit(string $twelveDigits): string
    {
        $sum = 0;

        for ($i = 0; $i < 12; $i++) {
            $digit = (int) $twelveDigits[$i];
            $sum += ($i % 2 === 0) ? $digit : $digit * 3;
        }

        $mod = $sum % 10;

        return (string) ($mod === 0 ? 0 : 10 - $mod);
    }
}
