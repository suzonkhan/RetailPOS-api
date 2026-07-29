<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Checkout\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BkashWebhookController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $result = $this->checkoutService->handleWebhook($request->all());

        return response()->json($result);
    }
}
