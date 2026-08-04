<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Resources\Platform\PlatformBranchListResource;
use App\Services\Platform\PlatformBranchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlatformBranchController extends Controller
{
    public function __construct(
        private readonly PlatformBranchService $branches,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $paginator = $this->branches->list(
            request()->only(['search', 'status', 'plan_slug', 'page', 'per_page']),
        );

        return PlatformBranchListResource::collection($paginator)->additional([
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
