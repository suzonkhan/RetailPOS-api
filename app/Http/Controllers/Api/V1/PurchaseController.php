<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StorePurchaseRequest;
use App\Http\Resources\PurchaseResource;
use App\Models\Purchase;
use App\Services\Inventory\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseController extends Controller
{
    public function __construct(
        private readonly PurchaseService $purchases,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $paginator = $this->purchases->listForUser(
            request()->user(),
            request()->only(['supplier_id', 'from', 'to', 'search', 'per_page', 'page'])
        );

        return PurchaseResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $purchase = $this->purchases->createForUser(
            $request->user(),
            $request->validated()
        );

        return PurchaseResource::make($purchase)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Purchase $purchase): PurchaseResource
    {
        $purchase = $this->purchases->findForUser(request()->user(), $purchase);

        return PurchaseResource::make($purchase);
    }

    public function destroy(Purchase $purchase): JsonResponse
    {
        $this->purchases->deleteForUser(request()->user(), $purchase);

        return response()->json([
            'message' => 'Purchase deleted successfully.',
        ]);
    }
}
