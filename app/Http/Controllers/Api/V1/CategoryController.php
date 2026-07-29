<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\StoreCategoryRequest;
use App\Http\Requests\Catalog\UpdateCategoryRequest;
use App\Http\Resources\CategoryResource;
use App\Models\Category;
use App\Services\Catalog\CatalogPlanLimitService;
use App\Services\Catalog\CatalogScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CategoryController extends Controller
{
    public function __construct(
        private readonly CatalogScopeService $catalogScope,
        private readonly CatalogPlanLimitService $planLimits,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $store = $this->catalogScope->resolveStore(request()->user());

        $categories = Category::query()
            ->where('store_id', $store->id)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return CategoryResource::collection($categories)->additional([
            'meta' => [
                'count' => $categories->count(),
            ],
        ]);
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $user = $request->user();
        $store = $this->catalogScope->resolveStore($user);

        $this->planLimits->assertCanAddCategory($user->tenant);

        $data = $request->validated();

        $category = Category::query()->create([
            'tenant_id' => $user->tenant_id,
            'store_id' => $store->id,
            'name' => $data['name'],
            'sort_order' => $data['sort_order'] ?? 0,
            'is_active' => $data['is_active'] ?? true,
        ]);

        return CategoryResource::make($category)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Category $category): CategoryResource
    {
        $this->authorizeCategory($category);

        return CategoryResource::make($category);
    }

    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $this->authorizeCategory($category);

        $category->fill($request->validated());
        $category->save();

        return CategoryResource::make($category);
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->authorizeCategory($category);

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully.',
        ]);
    }

    private function authorizeCategory(Category $category): void
    {
        $store = request()->user()->tenant?->store;

        if ($store === null || (int) $category->store_id !== (int) $store->id) {
            abort(404);
        }
    }
}
