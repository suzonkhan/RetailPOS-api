<?php

namespace Tests\Feature\Rbac;

use App\Models\Plan;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            PlanSeeder::class,
            RolePermissionSeeder::class,
        ]);

        $this->owner = $this->registerOwner('8801712345710');
    }

    public function test_owner_can_list_and_create_users(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('meta.max_users', 2)
            ->assertJsonPath('meta.can_add_user', false)
            ->assertJsonCount(1, 'data');

        $this->upgradeTenantPlan($this->owner, 'startup-plus');
        $this->activateDefaultBranch($this->owner, 'startup-plus');

        $this->getJson('/api/v1/users')
            ->assertJsonPath('meta.can_add_user', true);

        $this->postJson('/api/v1/users', [
            'name' => 'Cashier One',
            'mobile' => '8801712345701',
            'pin' => '111111',
            'role' => 'cashier',
        ])->assertCreated()
            ->assertJsonPath('role', 'cashier');

        $this->assertDatabaseHas('users', [
            'mobile' => '8801712345701',
            'tenant_id' => $this->owner->tenant_id,
        ]);
    }

    public function test_startup_plan_blocks_second_user(): void
    {
        $this->activateDefaultBranch($this->owner, 'startup');

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/users', [
            'name' => 'First Staff',
            'mobile' => '8801712345702',
            'pin' => '222222',
            'role' => 'staff',
        ])->assertCreated();

        $this->postJson('/api/v1/users', [
            'name' => 'Extra User',
            'mobile' => '8801712345704',
            'pin' => '222223',
            'role' => 'staff',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_owner_can_update_and_delete_user_on_plus_plan(): void
    {
        $this->upgradeTenantPlan($this->owner, 'startup-plus');
        $this->activateDefaultBranch($this->owner, 'startup-plus');

        Sanctum::actingAs($this->owner);

        $create = $this->postJson('/api/v1/users', [
            'name' => 'Staff One',
            'mobile' => '8801712345703',
            'pin' => '333333',
            'role' => 'staff',
        ])->assertCreated();

        $userId = $create->json('id');

        $this->putJson("/api/v1/users/{$userId}", [
            'name' => 'Staff Updated',
            'role' => 'cashier',
        ])->assertOk()
            ->assertJsonPath('name', 'Staff Updated')
            ->assertJsonPath('role', 'cashier');

        $this->deleteJson("/api/v1/users/{$userId}")
            ->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $userId]);
    }

    public function test_owner_cannot_delete_self(): void
    {
        Sanctum::actingAs($this->owner);

        $this->deleteJson("/api/v1/users/{$this->owner->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user']);
    }

    public function test_change_pin_with_valid_current_pin(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/v1/auth/pin/change', [
            'current_pin' => '123456',
            'new_pin' => '654321',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'mobile' => '8801712345710',
            'pin' => '654321',
        ])->assertOk();
    }
}
