<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->can($permission)) {
            abort(Response::HTTP_FORBIDDEN, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
