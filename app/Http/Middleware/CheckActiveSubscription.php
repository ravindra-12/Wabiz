<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckActiveSubscription
{
    /**
     * Handle an incoming request.
     *
     * Verifies that the authenticated user has an active, non-expired subscription.
     * Returns 403 if no active subscription is found.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if (!$user->hasActiveSubscription()) {
            return response()->json([
                'status'  => false,
                'message' => 'No active subscription. Please subscribe to a plan to access this resource.',
                'data'    => [
                    'subscription_required' => true,
                    'plans_url'             => url('/api/billing/plans'),
                ],
            ], 403);
        }

        return $next($request);
    }
}
