<?php

namespace Tests\Feature\Staff;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Staff;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffTest extends TestCase
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

        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Staff Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345700',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345700')->firstOrFail();
        $this->activateDefaultBranch($this->owner, 'startup-pro');
    }

    public function test_owner_can_crud_staff_without_login(): void
    {
        Sanctum::actingAs($this->owner);

        $staff = $this->postJson('/api/v1/staff', [
            'name' => 'Rahim',
            'pay_type' => 'daily',
            'agreed_rate' => 500,
            'rate_unit' => 'per_day',
        ])->assertCreated()
            ->assertJsonPath('name', 'Rahim')
            ->assertJsonPath('has_login', false)
            ->assertJsonPath('user_id', null);

        $staffId = $staff->json('id');

        $this->getJson('/api/v1/staff')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/staff/{$staffId}", [
            'notes' => 'Morning shift',
        ])->assertOk()
            ->assertJsonPath('notes', 'Morning shift');

        $this->deleteJson("/api/v1/staff/{$staffId}")->assertOk();
        $this->assertSoftDeleted('staff', ['id' => $staffId]);
    }

    public function test_recording_payment_creates_staff_salary_expense(): void
    {
        Sanctum::actingAs($this->owner);

        $staffId = $this->postJson('/api/v1/staff', [
            'name' => 'Karim',
            'pay_type' => 'daily',
            'agreed_rate' => 500,
            'rate_unit' => 'per_day',
        ])->assertCreated()->json('id');

        $payment = $this->postJson("/api/v1/staff/{$staffId}/payments", [
            'amount' => 500,
            'expense_date' => '2026-07-18',
            'notes' => 'Day wage',
        ])->assertCreated()
            ->assertJsonPath('amount', 500)
            ->assertJsonPath('staff_id', $staffId);

        $this->assertDatabaseHas('expenses', [
            'id' => $payment->json('id'),
            'staff_id' => $staffId,
            'expense_category_id' => ExpenseCategory::query()
                ->where('name', ExpenseCategory::STAFF_SALARY_NAME)
                ->value('id'),
        ]);

        $this->getJson("/api/v1/staff/{$staffId}/payments?from=2026-07-01&to=2026-07-31")
            ->assertOk()
            ->assertJsonPath('meta.total_amount', 500)
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/expenses?staff_id='.$staffId)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_enable_login_links_user_and_respects_max_users(): void
    {
        Sanctum::actingAs($this->owner);

        $staffId = $this->postJson('/api/v1/staff', [
            'name' => 'Salma',
            'pay_type' => 'monthly',
            'agreed_rate' => 15000,
            'rate_unit' => 'per_month',
        ])->assertCreated()->json('id');

        $this->postJson("/api/v1/staff/{$staffId}/enable-login", [
            'mobile' => '8801712345711',
            'pin' => '654321',
            'role' => 'cashier',
        ])->assertOk()
            ->assertJsonPath('has_login', true)
            ->assertJsonPath('login_role', 'cashier')
            ->assertJsonPath('mobile', '8801712345711');

        $linkedUser = User::query()->where('mobile', '8801712345711')->firstOrFail();
        $this->assertDatabaseHas('staff', [
            'id' => $staffId,
            'user_id' => $linkedUser->id,
        ]);

        $this->postJson('/api/v1/auth/login', [
            'mobile' => '8801712345711',
            'pin' => '654321',
        ])->assertOk();

        $this->postJson("/api/v1/staff/{$staffId}/enable-login", [
            'mobile' => '8801712345712',
            'pin' => '111111',
            'role' => 'staff',
        ])->assertStatus(422);

        $staff2Id = $this->postJson('/api/v1/staff', [
            'name' => 'Extra',
            'pay_type' => 'daily',
        ])->assertCreated()->json('id');

        $count = User::query()
            ->where('tenant_id', $this->owner->tenant_id)
            ->where('is_platform_admin', false)
            ->count();
        $this->defaultStore($this->owner)->plan->update(['max_users' => $count]);

        $this->postJson("/api/v1/staff/{$staff2Id}/enable-login", [
            'mobile' => '8801712345713',
            'pin' => '222222',
            'role' => 'staff',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['mobile']);
    }

    public function test_staff_role_cannot_manage_staff(): void
    {
        $staffUser = $this->createTenantUser('staff');

        Sanctum::actingAs($staffUser);

        $this->getJson('/api/v1/staff')->assertForbidden();
    }

    public function test_cashier_cannot_enable_login(): void
    {
        Sanctum::actingAs($this->owner);

        $staffId = $this->postJson('/api/v1/staff', [
            'name' => 'NoLoginYet',
            'pay_type' => 'daily',
        ])->assertCreated()->json('id');

        $cashier = $this->createTenantUser('cashier');
        Sanctum::actingAs($cashier);

        $this->postJson("/api/v1/staff/{$staffId}/enable-login", [
            'mobile' => '8801712345799',
            'pin' => '123456',
            'role' => 'staff',
        ])->assertForbidden();
    }

    private function createTenantUser(string $role): User
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -8);
        $mobile = '88018'.$suffix;

        while (strlen($mobile) < 13) {
            $mobile .= '0';
        }
        $mobile = substr($mobile, 0, 13);

        return $this->createBranchUser($this->owner, $role, $mobile, 'startup-pro');
    }
}
