<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SubscriptionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class BillingController extends Controller
{
    protected SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    // ─── Plan Listing (Public) ───────────────────────────────────

    /**
     * Get subscription plans
     *
     * @OA\Get(
     *     path="/billing/plans",
     *     tags={"Billing"},
     *     summary="Get all subscription plans",
     *     description="Retrieve all active subscription plans with pricing, limits, and features. This is a public endpoint.",
     *     @OA\Response(
     *         response=200,
     *         description="Plans fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Plans fetched successfully"),
     *             @OA\Property(property="data", type="array",
     *                 @OA\Items(ref="#/components/schemas/SubscriptionPlan")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=500, description="Server error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function plans()
    {
        try {
            $plans = $this->subscriptionService->getPlans();

            return response()->json([
                'status'  => true,
                'message' => 'Plans fetched successfully',
                'data'    => $plans,
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error fetching plans',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Current Subscription ────────────────────────────────────

    /**
     * Get current subscription
     *
     * @OA\Get(
     *     path="/billing/subscription",
     *     tags={"Billing"},
     *     summary="Get current subscription",
     *     description="Retrieve the authenticated user's active subscription with plan details, remaining days, and usage stats.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Subscription fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="subscription", ref="#/components/schemas/UserSubscription"),
     *                 @OA\Property(property="usage", type="object",
     *                     @OA\Property(property="leads", type="object",
     *                         @OA\Property(property="current", type="integer", example=25),
     *                         @OA\Property(property="limit", type="integer", example=50)
     *                     ),
     *                     @OA\Property(property="messages", type="object",
     *                         @OA\Property(property="current", type="integer", example=40),
     *                         @OA\Property(property="limit", type="integer", example=100)
     *                     ),
     *                     @OA\Property(property="orders", type="object",
     *                         @OA\Property(property="current", type="integer", example=5),
     *                         @OA\Property(property="limit", type="integer", example=10)
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=404, description="No active subscription"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function currentSubscription()
    {
        try {
            $userId       = auth()->id();
            $subscription = $this->subscriptionService->getCurrentSubscription($userId);

            if (!$subscription) {
                return response()->json([
                    'status'  => false,
                    'message' => 'No active subscription found',
                    'data'    => null,
                ], 404);
            }

            // Build usage stats
            $usage = [
                'leads'    => $this->subscriptionService->checkUsageLimit($userId, 'leads'),
                'messages' => $this->subscriptionService->checkUsageLimit($userId, 'messages'),
                'orders'   => $this->subscriptionService->checkUsageLimit($userId, 'orders'),
            ];

            return response()->json([
                'status'  => true,
                'message' => 'Subscription fetched successfully',
                'data'    => [
                    'subscription'   => $subscription,
                    'days_remaining' => $subscription->daysRemaining(),
                    'usage'          => $usage,
                ],
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error fetching subscription',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Subscribe ───────────────────────────────────────────────

    /**
     * Subscribe to a plan
     *
     * @OA\Post(
     *     path="/billing/subscribe",
     *     tags={"Billing"},
     *     summary="Subscribe to a plan",
     *     description="Create a new subscription for the authenticated user. For free plans, subscription activates immediately. For paid plans, provide a payment ID from the gateway.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"plan_id", "gateway"},
     *             @OA\Property(property="plan_id", type="integer", example=1, description="Subscription plan ID"),
     *             @OA\Property(property="gateway", type="string", enum={"razorpay","stripe","manual"}, example="razorpay"),
     *             @OA\Property(property="payment_id", type="string", nullable=true, example="pay_ABC123", description="Payment/order ID from the gateway")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Subscription created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/UserSubscription")
     *         )
     *     ),
     *     @OA\Response(response=400, description="Already has active subscription"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function subscribe(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'plan_id'    => 'required|integer|exists:subscription_plans,id',
                'gateway'    => 'required|string|in:razorpay,stripe,manual',
                'payment_id' => 'sometimes|nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            $subscription = $this->subscriptionService->subscribe(
                auth()->id(),
                $request->plan_id,
                $request->gateway,
                $request->payment_id
            );

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Subscription created successfully',
                'data'    => $subscription,
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Subscription error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            $statusCode = str_contains($e->getMessage(), 'already has an active') ? 400 : 500;

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    // ─── Upgrade ─────────────────────────────────────────────────

    /**
     * Upgrade subscription plan
     *
     * @OA\Post(
     *     path="/billing/upgrade",
     *     tags={"Billing"},
     *     summary="Upgrade subscription plan",
     *     description="Upgrade to a different plan. Cancels the current subscription and creates a new one.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"plan_id", "gateway"},
     *             @OA\Property(property="plan_id", type="integer", example=2, description="New plan ID to upgrade to"),
     *             @OA\Property(property="gateway", type="string", enum={"razorpay","stripe","manual"}, example="razorpay"),
     *             @OA\Property(property="payment_id", type="string", nullable=true, example="pay_XYZ789")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Subscription upgraded successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/UserSubscription")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function upgrade(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'plan_id'    => 'required|integer|exists:subscription_plans,id',
                'gateway'    => 'required|string|in:razorpay,stripe,manual',
                'payment_id' => 'sometimes|nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            $subscription = $this->subscriptionService->upgradePlan(
                auth()->id(),
                $request->plan_id,
                $request->gateway,
                $request->payment_id
            );

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Subscription upgraded successfully',
                'data'    => $subscription,
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Upgrade error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error upgrading subscription',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Cancel ──────────────────────────────────────────────────

    /**
     * Cancel subscription
     *
     * @OA\Post(
     *     path="/billing/cancel",
     *     tags={"Billing"},
     *     summary="Cancel subscription",
     *     description="Cancel the authenticated user's active subscription.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Subscription cancelled successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/UserSubscription")
     *         )
     *     ),
     *     @OA\Response(response=400, description="No active subscription found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function cancel()
    {
        try {
            DB::beginTransaction();

            $subscription = $this->subscriptionService->cancelSubscription(auth()->id());

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Subscription cancelled successfully',
                'data'    => $subscription,
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Cancel error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            $statusCode = str_contains($e->getMessage(), 'No active') ? 400 : 500;

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    // ─── Renew ───────────────────────────────────────────────────

    /**
     * Renew subscription
     *
     * @OA\Post(
     *     path="/billing/renew",
     *     tags={"Billing"},
     *     summary="Renew subscription",
     *     description="Renew the user's most recent subscription plan with a new billing period.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"gateway"},
     *             @OA\Property(property="gateway", type="string", enum={"razorpay","stripe","manual"}, example="razorpay"),
     *             @OA\Property(property="payment_id", type="string", nullable=true, example="pay_RENEW456")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Subscription renewed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", ref="#/components/schemas/UserSubscription")
     *         )
     *     ),
     *     @OA\Response(response=400, description="No subscription to renew"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function renew(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'gateway'    => 'required|string|in:razorpay,stripe,manual',
                'payment_id' => 'sometimes|nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors(),
                ], 422);
            }

            DB::beginTransaction();

            $subscription = $this->subscriptionService->renewSubscription(
                auth()->id(),
                $request->gateway,
                $request->payment_id
            );

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Subscription renewed successfully',
                'data'    => $subscription,
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Renewal error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            $statusCode = str_contains($e->getMessage(), 'No subscription') ? 400 : 500;

            return response()->json([
                'status'  => false,
                'message' => $e->getMessage(),
            ], $statusCode);
        }
    }

    // ─── Billing History ─────────────────────────────────────────

    /**
     * Get billing history
     *
     * @OA\Get(
     *     path="/billing/history",
     *     tags={"Billing"},
     *     summary="Get billing history",
     *     description="Retrieve paginated payment transaction history for the authenticated user.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", example=15)),
     *     @OA\Response(
     *         response=200,
     *         description="Billing history fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function billingHistory(Request $request)
    {
        try {
            $perPage = $request->get('per_page', 15);
            $history = $this->subscriptionService->getBillingHistory(auth()->id(), $perPage);

            return response()->json([
                'status'  => true,
                'message' => 'Billing history fetched successfully',
                'data'    => $history,
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error fetching billing history',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ─── Payment Webhooks (Public) ───────────────────────────────

    /**
     * Razorpay payment webhook
     *
     * @OA\Post(
     *     path="/webhook/razorpay",
     *     tags={"Billing Webhooks"},
     *     summary="Razorpay payment webhook",
     *     description="Handles Razorpay payment events (payment.captured, payment.failed). This is a public endpoint called by Razorpay.",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="event", type="string", example="payment.captured"),
     *             @OA\Property(property="payload", type="object")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Webhook processed"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function razorpayWebhook(Request $request)
    {
        try {
            $payload = $request->all();

            Log::info('Razorpay webhook payload received.', [
                'event' => $payload['event'] ?? 'unknown',
            ]);

            $this->subscriptionService->handleRazorpayWebhook($payload);

            return response()->json([
                'status'  => true,
                'message' => 'Webhook processed successfully',
            ], 200);

        } catch (Exception $e) {
            Log::error('Razorpay webhook error: ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => 'Webhook processing failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Stripe payment webhook
     *
     * @OA\Post(
     *     path="/webhook/stripe",
     *     tags={"Billing Webhooks"},
     *     summary="Stripe payment webhook",
     *     description="Handles Stripe payment events (checkout.session.completed, payment_intent.succeeded, payment_intent.payment_failed). This is a public endpoint called by Stripe.",
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="type", type="string", example="checkout.session.completed"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=200, description="Webhook processed"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function stripeWebhook(Request $request)
    {
        try {
            $payload = $request->all();

            Log::info('Stripe webhook payload received.', [
                'type' => $payload['type'] ?? 'unknown',
            ]);

            $this->subscriptionService->handleStripeWebhook($payload);

            return response()->json([
                'status'  => true,
                'message' => 'Webhook processed successfully',
            ], 200);

        } catch (Exception $e) {
            Log::error('Stripe webhook error: ' . $e->getMessage());

            return response()->json([
                'status'  => false,
                'message' => 'Webhook processing failed',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
