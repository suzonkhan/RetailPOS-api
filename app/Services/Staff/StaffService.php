<?php

namespace App\Services\Staff;

use App\Models\Expense;
use App\Models\Staff;
use App\Models\User;
use App\Services\Catalog\CatalogScopeService;
use App\Services\Expenses\ExpenseService;
use App\Services\Users\UserService;
use App\Support\MobileNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffService
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly ExpenseService $expenses,
        private readonly UserService $users,
    ) {}

    /**
     * @return Collection<int, Staff>
     */
    public function listForUser(User $user, array $filters = []): Collection
    {
        $store = $this->catalogScope->resolveStore($user);

        $query = Staff::query()
            ->with('user.roles')
            ->where('store_id', $store->id)
            ->orderBy('name');

        if (isset($filters['active']) && ($filters['active'] === '1' || $filters['active'] === true || $filters['active'] === 'true')) {
            $query->where('is_active', true);
        }

        if (isset($filters['unlinked']) && ($filters['unlinked'] === '1' || $filters['unlinked'] === true || $filters['unlinked'] === 'true')) {
            $query->whereNull('user_id');
        }

        return $query->get();
    }

    public function createForUser(User $user, array $data): Staff
    {
        $store = $this->catalogScope->resolveStore($user);

        $mobile = null;
        if (! empty($data['mobile'])) {
            $mobile = MobileNormalizer::normalize($data['mobile']);
            if (! MobileNormalizer::isValidBangladeshMobile($mobile)) {
                throw ValidationException::withMessages([
                    'mobile' => ['Enter a valid Bangladesh mobile number.'],
                ]);
            }
        }

        return Staff::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $data['name'],
            'mobile' => $mobile,
            'pay_type' => $data['pay_type'] ?? 'monthly',
            'agreed_rate' => $data['agreed_rate'] ?? null,
            'rate_unit' => $data['rate_unit'] ?? null,
            'joined_at' => $data['joined_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'user_id' => null,
        ]);
    }

    public function findForUser(User $user, Staff $staff): Staff
    {
        $this->authorize($user, $staff);

        return $staff->load('user.roles');
    }

    public function updateForUser(User $user, Staff $staff, array $data): Staff
    {
        $this->authorize($user, $staff);

        if (array_key_exists('mobile', $data)) {
            if ($data['mobile'] === null || $data['mobile'] === '') {
                $staff->mobile = null;
            } else {
                $mobile = MobileNormalizer::normalize($data['mobile']);
                if (! MobileNormalizer::isValidBangladeshMobile($mobile)) {
                    throw ValidationException::withMessages([
                        'mobile' => ['Enter a valid Bangladesh mobile number.'],
                    ]);
                }
                $staff->mobile = $mobile;
            }
        }

        $staff->fill([
            'name' => $data['name'] ?? $staff->name,
            'pay_type' => $data['pay_type'] ?? $staff->pay_type,
            'agreed_rate' => array_key_exists('agreed_rate', $data) ? $data['agreed_rate'] : $staff->agreed_rate,
            'rate_unit' => array_key_exists('rate_unit', $data) ? $data['rate_unit'] : $staff->rate_unit,
            'joined_at' => array_key_exists('joined_at', $data) ? $data['joined_at'] : $staff->joined_at,
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $staff->notes,
            'is_active' => $data['is_active'] ?? $staff->is_active,
        ]);
        $staff->save();

        return $staff->fresh('user.roles');
    }

    public function deleteForUser(User $user, Staff $staff): void
    {
        $this->authorize($user, $staff);

        if ($staff->user_id !== null) {
            throw ValidationException::withMessages([
                'staff' => ['Unlink or delete the login user before deleting this staff member.'],
            ]);
        }

        $staff->delete();
    }

    public function listPayments(User $user, Staff $staff, array $filters = []): LengthAwarePaginator
    {
        $this->authorize($user, $staff);

        $filters['staff_id'] = $staff->id;

        return $this->expenses->listForUser($user, $filters);
    }

    public function paymentsTotal(User $user, Staff $staff, array $filters = []): string
    {
        $this->authorize($user, $staff);

        $filters['staff_id'] = $staff->id;

        return $this->expenses->totalAmountForUser($user, $filters);
    }

    public function recordPayment(User $user, Staff $staff, array $data): Expense
    {
        $this->authorize($user, $staff);

        if (! $staff->is_active) {
            throw ValidationException::withMessages([
                'staff' => ['Cannot record payment for inactive staff.'],
            ]);
        }

        return $this->expenses->createStaffPayment($user, $staff, $data);
    }

    public function enableLogin(User $actor, Staff $staff, array $data): Staff
    {
        $this->authorize($actor, $staff);

        if (! $staff->is_active) {
            throw ValidationException::withMessages([
                'staff' => ['Cannot enable login for inactive staff.'],
            ]);
        }

        if ($staff->user_id !== null) {
            throw ValidationException::withMessages([
                'staff' => ['This staff member already has a login account.'],
            ]);
        }

        $mobile = MobileNormalizer::normalize($data['mobile']);
        if (! MobileNormalizer::isValidBangladeshMobile($mobile)) {
            throw ValidationException::withMessages([
                'mobile' => ['Enter a valid Bangladesh mobile number.'],
            ]);
        }

        $tenant = $actor->tenant;
        if ($tenant === null) {
            abort(404);
        }

        return DB::transaction(function () use ($actor, $staff, $data, $mobile, $tenant) {
            $user = $this->users->create($tenant, [
                'name' => $staff->name,
                'mobile' => $mobile,
                'pin' => $data['pin'],
                'role' => $data['role'],
            ]);

            $staff->user_id = $user->id;
            $staff->mobile = $mobile;
            $staff->save();

            return $staff->fresh('user.roles');
        });
    }

    public function authorize(User $user, Staff $staff): void
    {
        $store = $user->tenant?->store;

        if ($store === null || (int) $staff->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
