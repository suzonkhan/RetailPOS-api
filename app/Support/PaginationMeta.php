<?php

namespace App\Support;

/**
 * Extra keys for paginated Resource collections.
 *
 * Laravel already emits meta.current_page, per_page, total, and last_page.
 * Re-adding those via additional() is merged with array_merge_recursive and
 * turns the scalars into arrays, which breaks admin table paginators.
 */
final class PaginationMeta
{
    /**
     * @param  array<string, mixed>  $extra
     * @return array{meta: array<string, mixed>}
     */
    public static function extra(array $extra): array
    {
        unset(
            $extra['current_page'],
            $extra['per_page'],
            $extra['total'],
            $extra['last_page'],
        );

        return ['meta' => $extra];
    }
}
