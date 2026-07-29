<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StorePaymentMethodRequest;
use App\Http\Requests\Settings\UpdatePaymentMethodRequest;
use App\Http\Resources\PaymentMethodResource;
use App\Models\PaymentMethod;
use App\Services\Settings\PaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PaymentMethodController extends Controller
{
    public function __construct(
        private readonly PaymentMethodService $paymentMethodService,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $user = request()->user();
        $activeOnly = ! $user->can('settings.payment_methods');
        $methods = $this->paymentMethodService->listForUser($user, $activeOnly);

        return PaymentMethodResource::collection($methods)->additional([
            'meta' => [
                'count' => $methods->count(),
            ],
        ]);
    }

    public function store(StorePaymentMethodRequest $request): JsonResponse
    {
        $method = $this->paymentMethodService->storeForUser(
            $request->user(),
            $request->validated()
        );

        return PaymentMethodResource::make($method)
            ->response()
            ->setStatusCode(201);
    }

    public function show(PaymentMethod $paymentMethod): PaymentMethodResource
    {
        $this->authorizePaymentMethod($paymentMethod);

        return PaymentMethodResource::make($paymentMethod);
    }

    public function update(UpdatePaymentMethodRequest $request, PaymentMethod $paymentMethod): PaymentMethodResource
    {
        $this->authorizePaymentMethod($paymentMethod);

        $method = $this->paymentMethodService->update($paymentMethod, $request->validated());

        return PaymentMethodResource::make($method);
    }

    public function destroy(PaymentMethod $paymentMethod): JsonResponse
    {
        $this->authorizePaymentMethod($paymentMethod);

        $paymentMethod->delete();

        return response()->json([
            'message' => 'Payment method deleted successfully.',
        ]);
    }

    private function authorizePaymentMethod(PaymentMethod $paymentMethod): void
    {
        $store = request()->user()->tenant?->store;

        if ($store === null || $paymentMethod->store_id !== $store->id) {
            abort(404);
        }
    }
}
