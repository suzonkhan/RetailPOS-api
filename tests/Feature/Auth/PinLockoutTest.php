<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PinLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PlanSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_account_locks_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'mobile' => '8801712345710',
            'pin_hash' => Hash::make('123456'),
        ]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', [
                'mobile' => '8801712345710',
                'pin' => '000000',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', [
            'mobile' => '8801712345710',
            'pin' => '123456',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);

        $user = User::query()->where('mobile', '8801712345710')->firstOrFail();
        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->locked_until->isFuture());
    }

    public function test_successful_login_clears_failed_attempts(): void
    {
        User::factory()->create([
            'mobile' => '8801712345711',
            'pin_hash' => Hash::make('123456'),
            'failed_login_attempts' => 3,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'mobile' => '8801712345711',
            'pin' => '123456',
        ])->assertOk();

        $user = User::query()->where('mobile', '8801712345711')->firstOrFail();
        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }
}
