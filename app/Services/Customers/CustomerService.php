<?php

namespace App\Services\Customers;

use App\Models\Customer;
use App\Models\CustomerDue;
use App\Models\User;
use App\Services\Sales\SalesScopeService;
use App\Support\MobileNormalizer;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    public function __construct(
        private readonly SalesScopeService $scope,
    ) {}

    public function listForUser(User $user, array $filters = []): LengthAwarePaginator
    {
        $store = $this->scope->resolveStore($user);

        $query = Customer::query()
            ->where('store_id', $store->id)
            ->with(['openDues' => fn ($q) => $q->where('status', CustomerDue::STATUS_OPEN)])
            ->orderBy('name');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $due = $filters['due'] ?? null;
        if ($due === 'due') {
            $query->whereHas(
                'openDues',
                fn ($q) => $q->where('balance', '>', 0)
            );
        } elseif ($due === 'paid') {
            $query->whereDoesntHave(
                'openDues',
                fn ($q) => $q->where('balance', '>', 0)
            );
        }

        $perPage = min(max((int) ($filters['per_page'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    public function delete(Customer $customer): void
    {
        $openBalance = (float) $customer->openDues()->sum('balance');

        if ($openBalance > 0) {
            throw ValidationException::withMessages([
                'customer' => ['Cannot delete a customer with outstanding due balance.'],
            ]);
        }

        $customer->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeForUser(User $user, array $data): Customer
    {
        $store = $this->scope->resolveStore($user);

        $mobile = $this->normalizeMobile($data['mobile'] ?? null);

        if ($mobile !== null) {
            $existing = Customer::withTrashed()
                ->where('tenant_id', $user->tenant_id)
                ->where('mobile', $mobile)
                ->first();

            if ($existing !== null) {
                if (! $existing->trashed()) {
                    throw ValidationException::withMessages([
                        'mobile' => ['A customer with this mobile already exists in this shop.'],
                    ]);
                }

                $existing->restore();
                $existing->fill([
                    'store_id' => $store->id,
                    'name' => $data['name'],
                    'email' => $data['email'] ?? null,
                    'address' => $data['address'] ?? null,
                    'notes' => $data['notes'] ?? null,
                ]);

                if (! empty($data['uuid']) && $existing->uuid !== $data['uuid']) {
                    $uuidTaken = Customer::withTrashed()
                        ->where('uuid', $data['uuid'])
                        ->where('id', '!=', $existing->id)
                        ->exists();

                    if (! $uuidTaken) {
                        $existing->uuid = $data['uuid'];
                    }
                }

                $existing->save();

                return $existing->fresh() ?? $existing;
            }
        }

        $attributes = [
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $data['name'],
            'mobile' => $mobile,
            'email' => $data['email'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        if (! empty($data['uuid'])) {
            $attributes['uuid'] = $data['uuid'];
        }

        return Customer::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Customer $customer, array $data): Customer
    {
        if (array_key_exists('name', $data)) {
            $customer->name = $data['name'];
        }

        if (array_key_exists('mobile', $data)) {
            $mobile = $this->normalizeMobile($data['mobile']);
            $this->assertMobileUnique($customer->tenant_id, $mobile, $customer->id);
            $customer->mobile = $mobile;
        }

        if (array_key_exists('email', $data)) {
            $customer->email = $data['email'];
        }

        if (array_key_exists('address', $data)) {
            $customer->address = $data['address'];
        }

        if (array_key_exists('notes', $data)) {
            $customer->notes = $data['notes'];
        }

        $customer->save();

        return $customer;
    }

    private function normalizeMobile(?string $mobile): ?string
    {
        if ($mobile === null || $mobile === '') {
            return null;
        }

        $normalized = MobileNormalizer::normalize($mobile);

        if (! MobileNormalizer::isValidBangladeshMobile($normalized)) {
            throw ValidationException::withMessages([
                'mobile' => ['Invalid Bangladesh mobile number.'],
            ]);
        }

        return $normalized;
    }

    private function assertMobileUnique(int $tenantId, ?string $mobile, ?int $exceptId = null): void
    {
        if ($mobile === null) {
            return;
        }

        $exists = Customer::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('mobile', $mobile)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'mobile' => ['A customer with this mobile already exists in this shop.'],
            ]);
        }
    }
}
