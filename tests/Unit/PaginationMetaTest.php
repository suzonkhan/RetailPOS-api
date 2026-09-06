<?php

namespace Tests\Unit;

use App\Support\PaginationMeta;
use PHPUnit\Framework\TestCase;

class PaginationMetaTest extends TestCase
{
    public function test_extra_strips_laravel_pagination_keys(): void
    {
        $this->assertSame(
            [
                'meta' => [
                    'users' => [],
                    'total_amount' => 10.5,
                ],
            ],
            PaginationMeta::extra([
                'current_page' => 1,
                'per_page' => 15,
                'total' => 30,
                'last_page' => 2,
                'users' => [],
                'total_amount' => 10.5,
            ])
        );
    }
}
