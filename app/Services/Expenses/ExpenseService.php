<?php

namespace App\Services\Expenses;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Purchase;
use App\Models\Staff;
use App\Models\User;
use App\Services\Catalog\CatalogScopeService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ExpenseService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly ExpenseCategoryService $categories,
    ) {}

    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $store = $this->catalogScope->resolveStore($user);
        $this->categories->ensureSystemCategories($store);

        $query = Expense::query()
            ->with(['category', 'staff', 'supplier', 'purchase'])
            ->where('store_id', $store->id);

        $this->applyFilters($query, $filters);

        $query->orderByDesc('expense_date')->orderByDesc('id');

        $perPage = min(max((int) ($filters['per_page'] ?? 20), 1), 100);

        return $query->paginate($perPage);
    }

    public function totalAmountForUser(User $user, array $filters = []): string
    {
        $store = $this->catalogScope->resolveStore($user);

        $query = Expense::query()->where('store_id', $store->id);
        $this->applyFilters($query, $filters);

        return (string) $query->sum('amount');
    }

    public function createForUser(User $user, array $data): Expense
    {
        $store = $this->catalogScope->resolveStore($user);
        $category = ExpenseCategory::query()->findOrFail($data['expense_category_id']);
        $this->categories->authorize($user, $category);

        if ($category->isStaffSalary()) {
            throw ValidationException::withMessages([
                'expense_category_id' => ['Staff Salary expenses must be recorded from the staff profile.'],
            ]);
        }

        if ($category->isPurchases()) {
            throw ValidationException::withMessages([
                'expense_category_id' => ['Purchase expenses are recorded automatically when you save a purchase.'],
            ]);
        }

        if (! empty($data['staff_id'])) {
            throw ValidationException::withMessages([
                'staff_id' => ['Staff can only be linked when recording salary payments.'],
            ]);
        }

        if (! empty($data['purchase_id'])) {
            throw ValidationException::withMessages([
                'purchase_id' => ['Purchases can only be linked when recording inventory purchases.'],
            ]);
        }

        if (! empty($data['supplier_id']) && ! $this->catalogScope->supplierBelongsToStore((int) $data['supplier_id'], $store)) {
            throw ValidationException::withMessages([
                'supplier_id' => ['Supplier not found for this store.'],
            ]);
        }

        return Expense::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'expense_category_id' => $category->id,
            'staff_id' => null,
            'supplier_id' => $data['supplier_id'] ?? null,
            'purchase_id' => null,
            'title' => $data['title'],
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ])->load(['category', 'staff', 'supplier', 'purchase']);
    }

    public function createFromPurchase(User $user, Purchase $purchase): Expense
    {
        $store = $this->catalogScope->resolveStore($user);
        $category = $this->categories->ensurePurchasesCategory($store);

        return Expense::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'expense_category_id' => $category->id,
            'staff_id' => null,
            'supplier_id' => $purchase->supplier_id,
            'purchase_id' => $purchase->id,
            'title' => $purchase->purchase_number,
            'amount' => $purchase->total,
            'expense_date' => $purchase->purchased_at->toDateString(),
            'notes' => $purchase->notes,
            'created_by' => $user->id,
        ])->load(['category', 'staff', 'supplier', 'purchase']);
    }

    public function createStaffPayment(User $user, Staff $staff, array $data): Expense
    {
        $store = $this->catalogScope->resolveStore($user);
        $category = $this->categories->ensureStaffSalaryCategory($store);

        $title = $data['title'] ?? sprintf(
            '%s — %s',
            $staff->name,
            $data['expense_date']
        );

        return Expense::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'expense_category_id' => $category->id,
            'staff_id' => $staff->id,
            'supplier_id' => null,
            'purchase_id' => null,
            'title' => $title,
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ])->load(['category', 'staff', 'supplier', 'purchase']);
    }

    public function findForUser(User $user, Expense $expense): Expense
    {
        $this->authorize($user, $expense);

        return $expense->load(['category', 'staff', 'supplier', 'purchase']);
    }

    public function updateForUser(User $user, Expense $expense, array $data): Expense
    {
        $this->authorize($user, $expense);
        $store = $this->catalogScope->resolveStore($user);
        $expense->loadMissing('category');

        $isSalary = $expense->category?->isStaffSalary() ?? false;
        $isPurchase = $expense->category?->isPurchases() ?? false;

        if ($isSalary || $isPurchase) {
            if (isset($data['expense_category_id']) && (int) $data['expense_category_id'] !== (int) $expense->expense_category_id) {
                throw ValidationException::withMessages([
                    'expense_category_id' => [
                        $isSalary
                            ? 'Staff Salary category cannot be changed.'
                            : 'Purchases category cannot be changed.',
                    ],
                ]);
            }

            if ($isSalary) {
                if (array_key_exists('supplier_id', $data) && $data['supplier_id'] !== null) {
                    throw ValidationException::withMessages([
                        'supplier_id' => ['Salary expenses cannot have a supplier.'],
                    ]);
                }

                if (array_key_exists('staff_id', $data) && (int) $data['staff_id'] !== (int) $expense->staff_id) {
                    throw ValidationException::withMessages([
                        'staff_id' => ['Staff cannot be changed on a salary payment.'],
                    ]);
                }
            }

            if ($isPurchase) {
                if (array_key_exists('supplier_id', $data) && (int) ($data['supplier_id'] ?? 0) !== (int) $expense->supplier_id) {
                    throw ValidationException::withMessages([
                        'supplier_id' => ['Vendor cannot be changed on a purchase expense.'],
                    ]);
                }

                if (array_key_exists('purchase_id', $data) && (int) ($data['purchase_id'] ?? 0) !== (int) $expense->purchase_id) {
                    throw ValidationException::withMessages([
                        'purchase_id' => ['Purchase cannot be changed on a purchase expense.'],
                    ]);
                }
            }

            $expense->fill([
                'title' => $data['title'] ?? $expense->title,
                'amount' => $data['amount'] ?? $expense->amount,
                'expense_date' => $data['expense_date'] ?? $expense->expense_date,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $expense->notes,
            ]);
        } else {
            if (isset($data['expense_category_id'])) {
                $category = ExpenseCategory::query()->findOrFail($data['expense_category_id']);
                $this->categories->authorize($user, $category);

                if ($category->isStaffSalary()) {
                    throw ValidationException::withMessages([
                        'expense_category_id' => ['Cannot change an expense to Staff Salary from here.'],
                    ]);
                }

                if ($category->isPurchases()) {
                    throw ValidationException::withMessages([
                        'expense_category_id' => ['Cannot change an expense to Purchases from here.'],
                    ]);
                }

                $expense->expense_category_id = $category->id;
            }

            if (array_key_exists('supplier_id', $data)) {
                if ($data['supplier_id'] !== null && ! $this->catalogScope->supplierBelongsToStore((int) $data['supplier_id'], $store)) {
                    throw ValidationException::withMessages([
                        'supplier_id' => ['Supplier not found for this store.'],
                    ]);
                }
                $expense->supplier_id = $data['supplier_id'];
            }

            $expense->fill([
                'title' => $data['title'] ?? $expense->title,
                'amount' => $data['amount'] ?? $expense->amount,
                'expense_date' => $data['expense_date'] ?? $expense->expense_date,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $expense->notes,
            ]);
        }

        $expense->save();

        return $expense->fresh(['category', 'staff', 'supplier', 'purchase']);
    }

    public function deleteForUser(User $user, Expense $expense): void
    {
        $this->authorize($user, $expense);
        $expense->delete();
    }

    public function authorize(User $user, Expense $expense): void
    {
        $store = $user->tenant?->store;

        if ($store === null || (int) $expense->store_id !== (int) $store->id) {
            abort(404);
        }
    }

    /**
     * @param  Builder<Expense>  $query
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['from'])) {
            $query->whereDate('expense_date', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('expense_date', '<=', $filters['to']);
        }

        if (! empty($filters['expense_category_id'])) {
            $query->where('expense_category_id', $filters['expense_category_id']);
        }

        if (! empty($filters['staff_id'])) {
            $query->where('staff_id', $filters['staff_id']);
        }

        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', $filters['supplier_id']);
        }

        if (! empty($filters['purchase_id'])) {
            $query->where('purchase_id', $filters['purchase_id']);
        }

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('title', 'like', $search)
                    ->orWhere('notes', 'like', $search);
            });
        }
    }
}
