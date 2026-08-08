<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * @param  string  ...$permissions  Permission names; pipe `|` means OR within a segment.
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $allowed = [];
        foreach ($permissions as $permission) {
            foreach (explode('|', $permission) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $allowed[] = $name;
                }
            }
        }

        foreach ($allowed as $name) {
            if ($user->can($name)) {
                return $next($request);
            }
        }

        abort(Response::HTTP_FORBIDDEN, 'You do not have permission to perform this action.');
    }
}
