<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->orderBy('monthly_price')
            ->get();

        return PlanResource::collection($plans);
    }
}
