<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionResource;
use Illuminate\Http\JsonResponse;

class TenantSubscriptionController extends Controller
{
    public function show(): JsonResponse
    {
        $user = request()->user()->load(['tenant.plan', 'roles']);

        return response()->json([
            'subscription' => SubscriptionResource::make($user)->resolve(),
        ]);
    }
}
