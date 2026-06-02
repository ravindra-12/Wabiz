<?php

namespace App\Http\Middleware;

use App\Services\SubscriptionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimit
{
    protected SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * Handle an incoming request.
     *
     * Checks if the authenticated user has exceeded their plan's limit
     * for the specified resource type.
     *
     * Usage in routes: middleware('usage.limit:leads')
     *
     * @param Request $request
     * @param Closure $next
     * @param string  $resource  leads|messages|orders
     * @return Response
     */
    public function handle(Request $request, Closure $next, string $resource): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status'  => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $check = $this->subscriptionService->checkUsageLimit($user->id, $resource);

        if (!$check['allowed']) {
            return response()->json([
                'status'  => false,
                'message' => "You have reached the maximum {$resource} limit for your plan. Please upgrade to continue.",
                'data'    => [
                    'resource'  => $resource,
                    'current'   => $check['current'],
                    'limit'     => $check['limit'],
                    'plans_url' => url('/api/billing/plans'),
                ],
            ], 429);
        }

        return $next($request);
    }
}
