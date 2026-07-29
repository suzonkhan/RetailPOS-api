<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Staff\EnableStaffLoginRequest;
use App\Http\Requests\Staff\StoreStaffPaymentRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\ExpenseResource;
use App\Http\Resources\StaffResource;
use App\Models\Staff;
use App\Services\Staff\StaffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staff,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $items = $this->staff->listForUser(
            request()->user(),
            request()->only(['active', 'unlinked'])
        );

        return StaffResource::collection($items)->additional([
            'meta' => [
                'count' => $items->count(),
            ],
        ]);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = $this->staff->createForUser($request->user(), $request->validated());

        return StaffResource::make($staff)
            ->response()
            ->setStatusCode(201);
    }

    public function show(Staff $staff): StaffResource
    {
        $staff = $this->staff->findForUser(request()->user(), $staff);

        return StaffResource::make($staff);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): StaffResource
    {
        $staff = $this->staff->updateForUser(
            $request->user(),
            $staff,
            $request->validated()
        );

        return StaffResource::make($staff);
    }

    public function destroy(Staff $staff): JsonResponse
    {
        $this->staff->deleteForUser(request()->user(), $staff);

        return response()->json([
            'message' => 'Staff deleted successfully.',
        ]);
    }

    public function payments(Staff $staff): AnonymousResourceCollection
    {
        $filters = request()->only(['from', 'to', 'per_page', 'page', 'search']);
        $paginator = $this->staff->listPayments(request()->user(), $staff, $filters);
        $totalAmount = $this->staff->paymentsTotal(request()->user(), $staff, $filters);

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

    public function storePayment(StoreStaffPaymentRequest $request, Staff $staff): JsonResponse
    {
        $expense = $this->staff->recordPayment(
            $request->user(),
            $staff,
            $request->validated()
        );

        return ExpenseResource::make($expense)
            ->response()
            ->setStatusCode(201);
    }

    public function enableLogin(EnableStaffLoginRequest $request, Staff $staff): StaffResource
    {
        $staff = $this->staff->enableLogin(
            $request->user(),
            $staff,
            $request->validated()
        );

        return StaffResource::make($staff);
    }
}
