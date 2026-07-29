<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreDuePaymentRequest;
use App\Http\Resources\DuePaymentResource;
use App\Models\Customer;
use App\Services\Sales\DuePaymentService;
use App\Services\Sales\SalesScopeService;
use Illuminate\Http\JsonResponse;

class DuePaymentController extends Controller
{
    public function __construct(
        private readonly DuePaymentService $duePaymentService,
        private readonly SalesScopeService $scope,
    ) {}

    public function store(StoreDuePaymentRequest $request, Customer $customer): JsonResponse
    {
        $this->scope->authorizeCustomer($request->user(), $customer);

        $duePayment = $this->duePaymentService->recordForCustomer(
            $request->user(),
            $customer,
            $request->validated()
        );

        return DuePaymentResource::make($duePayment)
            ->response()
            ->setStatusCode(201);
    }
}
