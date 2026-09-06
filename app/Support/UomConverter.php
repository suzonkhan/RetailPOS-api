<?php

namespace App\Support;

class UomConverter
{
    /**
     * @param  list<array{from_code?: string, to_code?: string, a?: string, b?: string, factor: float|int|string}>  $pairs
     */
    public static function toProductUomQty(
        float $displayQty,
        string $productUom,
        string $displayUom,
        array $pairs,
    ): float {
        if (! is_finite($displayQty)) {
            return 0.0;
        }

        if ($displayUom === '' || $displayUom === $productUom) {
            return $displayQty;
        }

        foreach ($pairs as $pair) {
            $from = $pair['from_code'] ?? $pair['a'] ?? null;
            $to = $pair['to_code'] ?? $pair['b'] ?? null;
            $factor = (float) ($pair['factor'] ?? 0);

            if ($from === null || $to === null || $factor <= 0) {
                continue;
            }

            if ($productUom === $from && $displayUom === $to) {
                return $displayQty / $factor;
            }

            if ($productUom === $to && $displayUom === $from) {
                return $displayQty * $factor;
            }
        }

        return $displayQty;
    }

    /**
     * @param  list<array{from_code?: string, to_code?: string, a?: string, b?: string, factor: float|int|string}>  $pairs
     */
    public static function fromProductUomQty(
        float $productQty,
        string $productUom,
        string $displayUom,
        array $pairs,
    ): float {
        if (! is_finite($productQty)) {
            return 0.0;
        }

        if ($displayUom === '' || $displayUom === $productUom) {
            return $productQty;
        }

        foreach ($pairs as $pair) {
            $from = $pair['from_code'] ?? $pair['a'] ?? null;
            $to = $pair['to_code'] ?? $pair['b'] ?? null;
            $factor = (float) ($pair['factor'] ?? 0);

            if ($from === null || $to === null || $factor <= 0) {
                continue;
            }

            if ($productUom === $from && $displayUom === $to) {
                return $productQty * $factor;
            }

            if ($productUom === $to && $displayUom === $from) {
                return $productQty / $factor;
            }
        }

        return $productQty;
    }
}
