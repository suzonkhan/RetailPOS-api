<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseCategoryRequest;
use App\Http\Requests\Expenses\UpdateExpenseCategoryRequest;
use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Services\Expenses\ExpenseCategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseCategoryController extends Controller
{
    public function __construct(
        private readonly ExpenseCategoryService $categories,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $categories = $this->categories->listForUser(
            request()->user(),
            request()->only(['active'])
        );

        return ExpenseCategoryResource::collection($categories)->additional([
            'meta' => [
                'count' => $categories->count(),
            ],
        ]);
    }

    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $category = $this->categories->createForUser($request->user(), $request->validated());

        return ExpenseCategoryResource::make($category)
            ->response()
            ->setStatusCode(201);
    }

    public function show(ExpenseCategory $expenseCategory): ExpenseCategoryResource
    {
        $category = $this->categories->findForUser(request()->user(), $expenseCategory);

        return ExpenseCategoryResource::make($category);
    }

    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $expenseCategory): ExpenseCategoryResource
    {
        $category = $this->categories->updateForUser(
            $request->user(),
            $expenseCategory,
            $request->validated()
        );

        return ExpenseCategoryResource::make($category);
    }

    public function destroy(ExpenseCategory $expenseCategory): JsonResponse
    {
        $this->categories->deleteForUser(request()->user(), $expenseCategory);

        return response()->json([
            'message' => 'Expense category deleted successfully.',
        ]);
    }
}
