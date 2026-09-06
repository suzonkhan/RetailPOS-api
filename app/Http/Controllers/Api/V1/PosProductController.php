<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Services\Pos\PosProductService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PosProductController extends Controller
{
    public function __construct(
        private readonly PosProductService $posProducts,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        $paginator = $this->posProducts->listForUser(
            request()->user(),
            request()->only([
                'search',
                'category_id',
                'per_page',
                'page',
            ])
        );

        return ProductResource::collection($paginator);
    }
}
