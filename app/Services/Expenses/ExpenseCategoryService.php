<?php

namespace App\Services\Expenses;

use App\Models\ExpenseCategory;
use App\Models\Store;
use App\Models\User;
use App\Services\Catalog\CatalogScopeService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ExpenseCategoryService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
    ) {}

    public function ensureStaffSalaryCategory(Store $store): ExpenseCategory
    {
        return ExpenseCategory::query()->firstOrCreate(
            [
                'store_id' => $store->id,
                'name' => ExpenseCategory::STAFF_SALARY_NAME,
            ],
            [
                'tenant_id' => $store->tenant_id,
                'description' => 'Staff wage and salary payments',
                'is_active' => true,
                'is_system' => true,
            ]
        );
    }

    /**
     * @return Collection<int, ExpenseCategory>
     */
    public function listForUser(User $user, array $filters = []): Collection
    {
        $store = $this->catalogScope->resolveStore($user);
        $this->ensureStaffSalaryCategory($store);

        $query = ExpenseCategory::query()
            ->where('store_id', $store->id)
            ->orderBy('name');

        if (isset($filters['active']) && ($filters['active'] === '1' || $filters['active'] === true || $filters['active'] === 'true')) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function createForUser(User $user, array $data): ExpenseCategory
    {
        $store = $this->catalogScope->resolveStore($user);

        if (strcasecmp($data['name'], ExpenseCategory::STAFF_SALARY_NAME) === 0) {
            throw ValidationException::withMessages([
                'name' => ['Staff Salary is a system category and cannot be created manually.'],
            ]);
        }

        return ExpenseCategory::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'is_system' => false,
        ]);
    }

    public function findForUser(User $user, ExpenseCategory $category): ExpenseCategory
    {
        $this->authorize($user, $category);

        return $category;
    }

    public function updateForUser(User $user, ExpenseCategory $category, array $data): ExpenseCategory
    {
        $this->authorize($user, $category);

        if ($category->is_system) {
            if (isset($data['name']) && strcasecmp($data['name'], $category->name) !== 0) {
                throw ValidationException::withMessages([
                    'name' => ['System category name cannot be changed.'],
                ]);
            }
        }

        if (isset($data['name']) && strcasecmp($data['name'], ExpenseCategory::STAFF_SALARY_NAME) === 0 && ! $category->is_system) {
            throw ValidationException::withMessages([
                'name' => ['Staff Salary is reserved for the system category.'],
            ]);
        }

        $category->fill([
            'name' => $data['name'] ?? $category->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $category->description,
            'is_active' => $data['is_active'] ?? $category->is_active,
        ]);
        $category->save();

        return $category->fresh();
    }

    public function deleteForUser(User $user, ExpenseCategory $category): void
    {
        $this->authorize($user, $category);

        if ($category->is_system) {
            throw new HttpException(409, 'System expense categories cannot be deleted.');
        }

        if ($category->expenses()->exists()) {
            throw new HttpException(409, 'Cannot delete an expense category that has expenses.');
        }

        $category->delete();
    }

    public function authorize(User $user, ExpenseCategory $category): void
    {
        $store = $user->tenant?->store;

        if ($store === null || (int) $category->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
