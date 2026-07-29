<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuthMeResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): AuthMeResource
    {
        $user = $request->user();
        $user->load(['tenant.plan', 'tenant.store', 'roles']);

        return AuthMeResource::make($user);
    }
}
