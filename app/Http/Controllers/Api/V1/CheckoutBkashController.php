<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutCreateRequest;
use App\Http\Requests\Checkout\CheckoutExecuteRequest;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;

class CheckoutBkashController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
    ) {}

    public function create(CheckoutCreateRequest $request): JsonResponse
    {
        $result = $this->checkoutService->createPayment(
            $request->user(),
            $request->validated(),
        );

        return response()->json($result);
    }

    public function execute(CheckoutExecuteRequest $request): JsonResponse
    {
        $result = $this->checkoutService->executePayment(
            $request->user(),
            $request->validated('payment_id'),
        );

        return response()->json($result);
    }
}
