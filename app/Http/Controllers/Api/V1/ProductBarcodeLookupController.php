<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ProductBarcodeLookupRequest;
use App\Http\Resources\ProductBarcodeSuggestionResource;
use App\Services\Catalog\ProductBarcodeLookupService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductBarcodeLookupController extends Controller
{
    public function __construct(
        private readonly ProductBarcodeLookupService $lookupService,
    ) {}

    public function __invoke(ProductBarcodeLookupRequest $request): AnonymousResourceCollection
    {
        $barcode = $request->validated('barcode');
        $suggestions = $this->lookupService->lookup($request->user(), $barcode);

        return ProductBarcodeSuggestionResource::collection($suggestions)->additional([
            'meta' => [
                'barcode' => $barcode,
                'count' => $suggestions->count(),
            ],
        ]);
    }
}
