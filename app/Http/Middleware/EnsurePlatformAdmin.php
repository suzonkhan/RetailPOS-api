<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->is_platform_admin) {
            abort(Response::HTTP_FORBIDDEN, 'Platform admin access required.');
        }

        return $next($request);
    }
}
