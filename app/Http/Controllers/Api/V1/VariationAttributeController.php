<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreVariationAttributeRequest;
use App\Http\Requests\Catalog\StoreVariationAttributeValueRequest;
use App\Http\Requests\Catalog\UpdateVariationAttributeRequest;
use App\Http\Requests\Catalog\UpdateVariationAttributeValueRequest;
use App\Http\Resources\VariationAttributeResource;
use App\Http\Resources\VariationAttributeValueResource;
use App\Models\VariationAttribute;
use App\Models\VariationAttributeValue;
use App\Services\Catalog\VariationAttributeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class VariationAttributeController extends Controller
{
    public function __construct(
        private readonly VariationAttributeService $service,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $attributes = $this->service->listForUser(request()->user());

        return VariationAttributeResource::collection($attributes)->additional([
            'meta' => ['count' => $attributes->count()],
        ]);
    }

    public function store(StoreVariationAttributeRequest $request): JsonResponse
    {
        $attribute = $this->service->storeForUser(
            $request->user(),
            $request->validated()
        );

        return VariationAttributeResource::make($attribute)
            ->response()
            ->setStatusCode(201);
    }

    public function show(VariationAttribute $variationAttribute): VariationAttributeResource
    {
        $this->service->authorizeAttribute($variationAttribute, request()->user());

        return VariationAttributeResource::make(
            $variationAttribute->load('values')
        );
    }

    public function update(
        UpdateVariationAttributeRequest $request,
        VariationAttribute $variationAttribute,
    ): VariationAttributeResource {
        $this->service->authorizeAttribute($variationAttribute, request()->user());

        $attribute = $this->service->update($variationAttribute, $request->validated());

        return VariationAttributeResource::make($attribute);
    }

    public function destroy(VariationAttribute $variationAttribute): JsonResponse
    {
        $this->service->authorizeAttribute($variationAttribute, request()->user());
        $this->service->delete($variationAttribute);

        return response()->json([
            'message' => 'Variation attribute deleted successfully.',
        ]);
    }

    public function storeValue(
        StoreVariationAttributeValueRequest $request,
        VariationAttribute $variationAttribute,
    ): JsonResponse {
        $this->service->authorizeAttribute($variationAttribute, request()->user());

        $value = $this->service->addValue($variationAttribute, $request->validated());

        return VariationAttributeValueResource::make($value)
            ->response()
            ->setStatusCode(201);
    }

    public function updateValue(
        UpdateVariationAttributeValueRequest $request,
        VariationAttributeValue $variationAttributeValue,
    ): VariationAttributeValueResource {
        $this->service->authorizeValue($variationAttributeValue, request()->user());

        $value = $this->service->updateValue($variationAttributeValue, $request->validated());

        return VariationAttributeValueResource::make($value);
    }

    public function destroyValue(VariationAttributeValue $variationAttributeValue): JsonResponse
    {
        $this->service->authorizeValue($variationAttributeValue, request()->user());
        $this->service->deleteValue($variationAttributeValue);

        return response()->json([
            'message' => 'Variation value deleted successfully.',
        ]);
    }
}
