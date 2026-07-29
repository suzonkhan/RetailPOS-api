<?php

namespace App\Services\Sales;

use App\Models\Product;
use App\Models\StoreSetting;

class VatLineCalculator
{
    /**
     * @return array{vat_rate: float, vat_type: ?string, vat_amount: float, line_subtotal: float, line_total: float}
     */
    public function calculate(Product $product, float $quantity, float $unitPrice, ?StoreSetting $settings): array
    {
        $lineSubtotal = round($quantity * $unitPrice, 2);

        $vatRate = $product->vat_rate;
        $vatType = $product->vat_type;

        if ($vatRate === null && $settings?->vat_adjust_on_sale && $settings->default_vat_percent !== null) {
            $vatRate = (float) $settings->default_vat_percent;
            $vatType = 'percent';
        }

        $vatRate = $vatRate !== null ? (float) $vatRate : 0.0;
        $vatType = $vatType ?? 'percent';

        $vatAmount = match ($vatType) {
            'fixed' => round($vatRate * $quantity, 2),
            default => round($lineSubtotal * ($vatRate / 100), 2),
        };

        return [
            'vat_rate' => $vatRate,
            'vat_type' => $vatType,
            'vat_amount' => $vatAmount,
            'line_subtotal' => $lineSubtotal,
            'line_total' => round($lineSubtotal + $vatAmount, 2),
        ];
    }
}
