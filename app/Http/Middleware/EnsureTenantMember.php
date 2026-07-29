<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->tenant_id === null || $user->is_platform_admin) {
            abort(Response::HTTP_FORBIDDEN, 'Tenant access required.');
        }

        return $next($request);
    }
}
