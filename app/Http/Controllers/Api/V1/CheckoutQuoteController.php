<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutQuoteRequest;
use App\Services\Checkout\CheckoutQuoteService;
use Illuminate\Http\JsonResponse;

class CheckoutQuoteController extends Controller
{
    public function __construct(
        private readonly CheckoutQuoteService $quoteService,
    ) {}

    public function __invoke(CheckoutQuoteRequest $request): JsonResponse
    {
        $quote = $this->quoteService->quote(
            $request->user()->tenant,
            $request->validated(),
        );

        return response()->json($quote);
    }
}
