<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $user->is_platform_admin || $user->tenant_id === null) {
            return $next($request);
        }

        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        $tenant = $user->tenant;

        if ($tenant === null) {
            return $next($request);
        }

        $tenant->syncSubscriptionStatus();
        $tenant->refresh();

        if (! $tenant->requiresSubscriptionPayment()) {
            return $next($request);
        }

        return response()->json([
            'trial_ended' => true,
            'subscribe_url' => '/subscription/pay',
        ], 402);
    }

    private function isWhitelisted(Request $request): bool
    {
        return $request->is(
            'api/v1/health',
            'api/v1/plans',
            'api/v1/auth/*',
            'api/v1/checkout/*',
            'api/v1/bkash/webhook',
            'api/v1/tenant/subscription',
        );
    }
}
