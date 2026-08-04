<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\CheckoutQuoteRequest;
use App\Models\SubscriptionInvoice;
use App\Services\Branch\BranchScopeService;
use App\Services\Checkout\CheckoutQuoteService;
use Illuminate\Http\JsonResponse;

class CheckoutQuoteController extends Controller
{
    public function __construct(
        private readonly CheckoutQuoteService $quoteService,
        private readonly BranchScopeService $branchScope,
    ) {}

    public function __invoke(CheckoutQuoteRequest $request): JsonResponse
    {
        $data = $request->validated();
        $store = null;

        if (($data['intent'] ?? SubscriptionInvoice::INTENT_RENEW) !== SubscriptionInvoice::INTENT_CREATE_BRANCH) {
            $store = $this->branchScope->resolveBranch($request->user(), (int) $data['store_id']);
        }

        $quote = $this->quoteService->quote(
            $request->user()->tenant,
            $data,
            $store,
        );

        return response()->json($quote);
    }
}
