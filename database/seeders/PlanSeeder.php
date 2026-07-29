<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Startup',
                'slug' => 'startup',
                'monthly_price' => 20,
                'yearly_price' => 240,
                'max_users' => 1,
                'max_stores' => 1,
                'max_categories' => 15,
                'max_products' => 200,
            ],
            [
                'name' => 'Startup Plus',
                'slug' => 'startup-plus',
                'monthly_price' => 49,
                'yearly_price' => 588,
                'max_users' => 4,
                'max_stores' => 1,
                'max_categories' => 25,
                'max_products' => 500,
            ],
            [
                'name' => 'Startup Pro',
                'slug' => 'startup-pro',
                'monthly_price' => 99,
                'yearly_price' => 1188,
                'max_users' => 10,
                'max_stores' => 1,
                'max_categories' => 40,
                'max_products' => 1000,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(
                ['slug' => $plan['slug']],
                $plan
            );
        }
    }
}
