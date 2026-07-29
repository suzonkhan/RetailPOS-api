<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreSaleRequest;
use App\Http\Resources\SaleResource;
use App\Models\Sale;
use App\Models\User;
use App\Services\Sales\SaleService;
use App\Services\Sales\SalesScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SaleController extends Controller
{
    public function __construct(
        private readonly SaleService $saleService,
        private readonly SalesScopeService $scope,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $paginator = $this->saleService->listForUser(
            request()->user(),
            request()->only([
                'customer_id',
                'order_id',
                'id',
                'user_id',
                'customer_mobile',
                'status',
                'from',
                'to',
                'payment',
                'per_page',
                'page',
            ])
        );

        $users = User::query()
            ->where('tenant_id', request()->user()->tenant_id)
            ->where('is_platform_admin', false)
            ->orderBy('name')
            ->get(['id', 'name']);

        return SaleResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'users' => $users->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                ])->values(),
            ],
        ]);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $result = $this->saleService->createForUser(
            $request->user(),
            $request->validated()
        );

        return SaleResource::make($result['sale'])
            ->response()
            ->setStatusCode($result['created'] ? 201 : 200);
    }

    public function show(Sale $sale): SaleResource
    {
        $this->scope->authorizeSale(request()->user(), $sale);

        return SaleResource::make($sale->load([
            'items.returnItems',
            'payments.paymentMethod',
            'customer',
            'user',
            'updatedBy',
            'returns.items',
        ]));
    }
}
