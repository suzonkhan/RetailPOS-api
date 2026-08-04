<?php

namespace App\Http\Middleware;

use App\Services\Branch\BranchScopeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function __construct(
        private readonly BranchScopeService $branchScope,
    ) {}

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

        if ($this->isBranchListRoute($request)) {
            return $next($request);
        }

        $store = $this->branchScope->resolveBranch($user);

        $store->syncSubscriptionStatus();
        $store->refresh();

        if (! $store->requiresSubscriptionPayment()) {
            return $next($request);
        }

        return response()->json([
            'trial_ended' => true,
            'branch_id' => $store->id,
            'subscribe_url' => '/app/settings?tab=subscription',
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
            'api/v1/tenant/branches',
            'api/v1/tenant/branches/*',
        );
    }

    private function isBranchListRoute(Request $request): bool
    {
        return $request->is('api/v1/tenant/branches')
            || $request->is('api/v1/tenant/branches/*')
            && $request->isMethod('GET')
            && str_contains($request->path(), 'subscription');
    }
}
