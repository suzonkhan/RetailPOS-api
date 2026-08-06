<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use App\Models\Customer;
use App\Models\CustomerDue;
use App\Services\Customers\CustomerService;
use App\Services\Sales\SalesScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly SalesScopeService $scope,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $paginator = $this->customerService->listForUser(
            request()->user(),
            request()->only(['search', 'per_page', 'page', 'due'])
        );

        return CustomerResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->storeForUser(
            $request->user(),
            $request->validated()
        );

        return CustomerResource::make($customer)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->scope->authorizeCustomer(request()->user(), $customer);

        $customer->load(['openDues' => fn ($q) => $q->where('status', CustomerDue::STATUS_OPEN)]);

        return CustomerResource::make($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): CustomerResource
    {
        $this->scope->authorizeCustomer($request->user(), $customer);

        $customer = $this->customerService->update($customer, $request->validated());

        return CustomerResource::make($customer);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->scope->authorizeCustomer(request()->user(), $customer);

        $this->customerService->delete($customer);

        return response()->json([
            'message' => 'Customer deleted successfully.',
        ]);
    }
}
