<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Expenses\StoreExpenseRequest;
use App\Http\Requests\Expenses\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Services\Expenses\ExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ExpenseController extends Controller
{
    public function __construct(
        private readonly ExpenseService $expenses,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $filters = request()->only([
            'from',
            'to',
            'expense_category_id',
            'staff_id',
            'supplier_id',
            'purchase_id',
            'search',
            'per_page',
            'page',
        ]);

        $paginator = $this->expenses->listForUser(request()->user(), $filters);
        $totalAmount = $this->expenses->totalAmountForUser(request()->user(), $filters);

        return ExpenseResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'total_amount' => (float) $totalAmount,
            ],
        ]);
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $expense = $this->expenses->createForUser($request->user(), $request->validated());

        return ExpenseResource::make($expense)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Expense $expense): ExpenseResource
    {
        $expense = $this->expenses->findForUser(request()->user(), $expense);

        return ExpenseResource::make($expense);
    }

    public function update(UpdateExpenseRequest $request, Expense $expense): ExpenseResource
    {
        $expense = $this->expenses->updateForUser(
            $request->user(),
            $expense,
            $request->validated()
        );

        return ExpenseResource::make($expense);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->expenses->deleteForUser(request()->user(), $expense);

        return response()->json([
            'message' => 'Expense deleted successfully.',
        ]);
    }
}
