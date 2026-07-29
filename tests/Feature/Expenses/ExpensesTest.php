<?php

namespace Tests\Feature\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpensesTest extends TestCase
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
            'shop_name' => 'Expense Shop',
            'owner_name' => 'Owner',
            'mobile' => '8801712345800',
            'pin' => '123456',
        ])->assertCreated();

        $this->owner = User::query()->where('mobile', '8801712345800')->firstOrFail();
        $this->owner->tenant->plan->update(['max_users' => 10]);
    }

    public function test_listing_categories_seeds_staff_salary(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/expense-categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Staff Salary', 'is_system' => true]);
    }

    public function test_owner_can_crud_expense_categories_and_expenses(): void
    {
        Sanctum::actingAs($this->owner);

        $category = $this->postJson('/api/v1/expense-categories', [
            'name' => 'Rent',
            'description' => 'Shop rent',
        ])->assertCreated()
            ->assertJsonPath('name', 'Rent');

        $categoryId = $category->json('id');

        $expense = $this->postJson('/api/v1/expenses', [
            'title' => 'July rent',
            'amount' => 15000,
            'expense_category_id' => $categoryId,
            'expense_date' => '2026-07-01',
            'notes' => 'Paid in cash',
        ])->assertCreated()
            ->assertJsonPath('title', 'July rent')
            ->assertJsonPath('amount', 15000);

        $expenseId = $expense->json('id');

        $this->getJson('/api/v1/expenses')
            ->assertOk()
            ->assertJsonPath('meta.total_amount', 15000);

        $this->putJson("/api/v1/expenses/{$expenseId}", [
            'amount' => 16000,
        ])->assertOk()
            ->assertJsonPath('amount', 16000);

        $this->deleteJson("/api/v1/expenses/{$expenseId}")->assertOk();
        $this->assertSoftDeleted('expenses', ['id' => $expenseId]);

        $this->deleteJson("/api/v1/expense-categories/{$categoryId}")->assertOk();
        $this->assertSoftDeleted('expense_categories', ['id' => $categoryId]);
    }

    public function test_cannot_delete_category_with_expenses_or_system_category(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->postJson('/api/v1/expense-categories', [
            'name' => 'Utilities',
        ])->assertCreated()->json('id');

        $this->postJson('/api/v1/expenses', [
            'title' => 'Electricity',
            'amount' => 2000,
            'expense_category_id' => $categoryId,
            'expense_date' => '2026-07-10',
        ])->assertCreated();

        $this->deleteJson("/api/v1/expense-categories/{$categoryId}")
            ->assertStatus(409);

        $systemId = ExpenseCategory::query()
            ->where('name', ExpenseCategory::STAFF_SALARY_NAME)
            ->value('id');

        if ($systemId === null) {
            $this->getJson('/api/v1/expense-categories')->assertOk();
            $systemId = ExpenseCategory::query()
                ->where('name', ExpenseCategory::STAFF_SALARY_NAME)
                ->value('id');
        }

        $this->deleteJson("/api/v1/expense-categories/{$systemId}")
            ->assertStatus(409);
    }

    public function test_cannot_create_staff_salary_via_expenses_endpoint(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/v1/expense-categories')->assertOk();

        $systemId = ExpenseCategory::query()
            ->where('name', ExpenseCategory::STAFF_SALARY_NAME)
            ->value('id');

        $this->postJson('/api/v1/expenses', [
            'title' => 'Rahim salary',
            'amount' => 500,
            'expense_category_id' => $systemId,
            'expense_date' => '2026-07-15',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['expense_category_id']);
    }

    public function test_staff_role_cannot_manage_expenses(): void
    {
        $staffUser = $this->createTenantUser('staff');

        Sanctum::actingAs($staffUser);

        $this->getJson('/api/v1/expense-categories')->assertForbidden();
        $this->getJson('/api/v1/expenses')->assertForbidden();
    }

    public function test_tenant_isolation_for_expenses(): void
    {
        Sanctum::actingAs($this->owner);

        $categoryId = $this->postJson('/api/v1/expense-categories', [
            'name' => 'Transport',
        ])->assertCreated()->json('id');

        $expenseId = $this->postJson('/api/v1/expenses', [
            'title' => 'Delivery',
            'amount' => 300,
            'expense_category_id' => $categoryId,
            'expense_date' => '2026-07-12',
        ])->assertCreated()->json('id');

        $this->postJson('/api/v1/auth/register', [
            'shop_name' => 'Other Shop',
            'owner_name' => 'Other',
            'mobile' => '8801712345801',
            'pin' => '123456',
        ])->assertCreated();

        $otherOwner = User::query()->where('mobile', '8801712345801')->firstOrFail();
        Sanctum::actingAs($otherOwner);

        $this->getJson("/api/v1/expenses/{$expenseId}")->assertNotFound();
        $this->getJson("/api/v1/expense-categories/{$categoryId}")->assertNotFound();
    }

    private function createTenantUser(string $role): User
    {
        $suffix = substr(str_replace('.', '', uniqid('', true)), -8);
        $mobile = '88017'.$suffix;

        while (strlen($mobile) < 13) {
            $mobile .= '0';
        }
        $mobile = substr($mobile, 0, 13);

        $this->actingAs($this->owner, 'sanctum')->postJson('/api/v1/users', [
            'name' => ucfirst($role),
            'mobile' => $mobile,
            'pin' => '123456',
            'role' => $role,
        ])->assertCreated();

        return User::query()->where('mobile', $mobile)->firstOrFail();
    }
}
