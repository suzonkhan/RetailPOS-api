<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
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

    public function test_register_creates_tenant_with_fifteen_day_startup_trial(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'owner_name' => 'Owner One',
            'mobile' => '01712345678',
            'pin' => '123456',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.mobile', '8801712345678')
            ->assertJsonPath('role', 'owner')
            ->assertJsonPath('subscription.is_trial', true)
            ->assertJsonPath('subscription.trial_days_remaining', 15)
            ->assertJsonPath('subscription.plan.slug', 'startup')
            ->assertJsonStructure(['token', 'permissions', 'tenant', 'store']);

        $this->assertDatabaseHas('tenants', [
            'name' => "Owner One's Store",
            'status' => Tenant::STATUS_TRIAL,
        ]);

        $this->assertDatabaseHas('stores', [
            'name' => 'main branch',
            'is_default' => true,
        ]);

        $tenant = Tenant::query()->first();
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertTrue($tenant->trial_ends_at->greaterThan(now()->addDays(14)));
    }

    public function test_register_accepts_optional_shop_name(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Demo Shop',
            'owner_name' => 'Owner One',
            'mobile' => '01712345679',
            'pin' => '123456',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('tenants', [
            'name' => "Owner One's Store",
        ]);

        $this->assertDatabaseHas('stores', [
            'name' => 'main branch',
        ]);
    }

    public function test_login_with_mobile_and_pin(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Login Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345679',
            'pin' => '654321',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => '8801712345679',
            'pin' => '654321',
            'device_name' => 'PHPUnit',
        ]);

        $response->assertOk()
            ->assertJsonPath('user.mobile', '8801712345679')
            ->assertJsonStructure([
                'token',
                'subscription' => ['trial_days_remaining', 'is_trial'],
            ]);
    }

    public function test_login_rejects_invalid_pin(): void
    {
        User::factory()->create([
            'mobile' => '8801712345680',
            'pin_hash' => '123456',
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'mobile' => '8801712345680',
            'pin' => '000000',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_me_returns_user_permissions_and_trial(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Me Shop',
            'owner_name' => 'Me Owner',
            'mobile' => '8801712345681',
            'pin' => '111111',
        ]);

        $token = $register->json('token');

        $response = $this->getJson('/api/v1/auth/me', [
            'Authorization' => 'Bearer '.$token,
        ]);

        $response->assertOk()
            ->assertJsonPath('role', 'owner')
            ->assertJsonPath('subscription.is_trial', true)
            ->assertJsonFragment(['dashboard.view']);
    }

    public function test_logout_revokes_access_token(): void
    {
        $register = $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Logout Shop',
            'owner_name' => 'Logout Owner',
            'mobile' => '8801712345682',
            'pin' => '222222',
        ]);

        $token = $register->json('token');

        $this->postJson('/api/v1/auth/logout', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertNull(PersonalAccessToken::findToken($token));
    }

    public function test_plans_endpoint_returns_seeded_plans(): void
    {
        $response = $this->getJson('/api/v1/plans');

        $response->assertOk()
            ->assertJsonCount(3);
    }
}
