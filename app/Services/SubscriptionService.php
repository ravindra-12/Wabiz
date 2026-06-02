<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Message;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Exception;

class SubscriptionService
{
    // ─── Plan Queries ────────────────────────────────────────────

    /**
     * Get all active subscription plans.
     */
    public function getPlans()
    {
        return SubscriptionPlan::active()
            ->orderBy('price', 'asc')
            ->get();
    }

    // ─── Subscription Lifecycle ──────────────────────────────────

    /**
     * Subscribe a user to a plan.
     *
     * @param int         $userId
     * @param int         $planId
     * @param string      $gateway    razorpay|stripe|manual
     * @param string|null $paymentId  Payment/order ID from gateway
     * @return UserSubscription
     * @throws Exception
     */
    public function subscribe(int $userId, int $planId, string $gateway, ?string $paymentId = null): UserSubscription
    {
        $plan = SubscriptionPlan::active()->findOrFail($planId);

        // Check if user already has an active subscription
        $existing = UserSubscription::where('user_id', $userId)
            ->active()
            ->first();

        if ($existing) {
            throw new Exception('User already has an active subscription. Use upgrade instead.');
        }

        // Calculate period
        $startDate  = Carbon::now();
        $expiryDate = $plan->billing_cycle === 'yearly'
            ? $startDate->copy()->addYear()
            : $startDate->copy()->addMonth();

        // Determine initial status
        $status = $plan->isFreePlan() ? 'active' : ($paymentId ? 'active' : 'pending');

        $subscription = UserSubscription::create([
            'user_id'              => $userId,
            'subscription_plan_id' => $plan->id,
            'payment_gateway'      => $gateway,
            'payment_id'           => $paymentId,
            'amount'               => $plan->price,
            'start_date'           => $startDate,
            'expiry_date'          => $expiryDate,
            'status'               => $status,
        ]);

        // Record payment transaction
        PaymentTransaction::create([
            'user_id'         => $userId,
            'subscription_id' => $subscription->id,
            'gateway'         => $gateway,
            'transaction_id'  => $paymentId,
            'amount'          => $plan->price,
            'currency'        => 'INR',
            'status'          => $status === 'active' ? 'success' : 'pending',
        ]);

        Log::info('User subscribed to plan.', [
            'user_id' => $userId,
            'plan_id' => $plan->id,
            'plan'    => $plan->name,
            'gateway' => $gateway,
            'status'  => $status,
        ]);

        return $subscription->load('plan');
    }

    /**
     * Upgrade user's subscription to a different plan.
     *
     * Cancels current subscription and creates a new one.
     *
     * @param int         $userId
     * @param int         $planId
     * @param string      $gateway
     * @param string|null $paymentId
     * @return UserSubscription
     * @throws Exception
     */
    public function upgradePlan(int $userId, int $planId, string $gateway, ?string $paymentId = null): UserSubscription
    {
        $currentSub = UserSubscription::where('user_id', $userId)
            ->active()
            ->first();

        if ($currentSub) {
            // Cancel the current subscription
            $currentSub->update(['status' => 'cancelled']);

            Log::info('Previous subscription cancelled for upgrade.', [
                'user_id'        => $userId,
                'old_sub_id'     => $currentSub->id,
                'old_plan_id'    => $currentSub->subscription_plan_id,
            ]);
        }

        // Create new subscription
        return $this->subscribe($userId, $planId, $gateway, $paymentId);
    }

    /**
     * Cancel the user's active subscription.
     *
     * @param int $userId
     * @return UserSubscription
     * @throws Exception
     */
    public function cancelSubscription(int $userId): UserSubscription
    {
        $subscription = UserSubscription::where('user_id', $userId)
            ->active()
            ->first();

        if (!$subscription) {
            throw new Exception('No active subscription found.');
        }

        $subscription->update(['status' => 'cancelled']);

        Log::info('Subscription cancelled.', [
            'user_id'         => $userId,
            'subscription_id' => $subscription->id,
        ]);

        return $subscription->fresh()->load('plan');
    }

    /**
     * Renew the user's current (or most recent) subscription.
     *
     * @param int         $userId
     * @param string      $gateway
     * @param string|null $paymentId
     * @return UserSubscription
     * @throws Exception
     */
    public function renewSubscription(int $userId, string $gateway, ?string $paymentId = null): UserSubscription
    {
        // Find the most recent subscription (active, expired, or cancelled)
        $lastSub = UserSubscription::where('user_id', $userId)
            ->latest('id')
            ->first();

        if (!$lastSub) {
            throw new Exception('No subscription found to renew. Please subscribe first.');
        }

        $plan = SubscriptionPlan::active()->findOrFail($lastSub->subscription_plan_id);

        // Calculate new period
        $startDate  = Carbon::now();
        $expiryDate = $plan->billing_cycle === 'yearly'
            ? $startDate->copy()->addYear()
            : $startDate->copy()->addMonth();

        $status = $plan->isFreePlan() ? 'active' : ($paymentId ? 'active' : 'pending');

        // Cancel old subscription if still active
        if ($lastSub->isActive()) {
            $lastSub->update(['status' => 'cancelled']);
        }

        $subscription = UserSubscription::create([
            'user_id'              => $userId,
            'subscription_plan_id' => $plan->id,
            'payment_gateway'      => $gateway,
            'payment_id'           => $paymentId,
            'amount'               => $plan->price,
            'start_date'           => $startDate,
            'expiry_date'          => $expiryDate,
            'status'               => $status,
        ]);

        // Record payment transaction
        PaymentTransaction::create([
            'user_id'         => $userId,
            'subscription_id' => $subscription->id,
            'gateway'         => $gateway,
            'transaction_id'  => $paymentId,
            'amount'          => $plan->price,
            'currency'        => 'INR',
            'status'          => $status === 'active' ? 'success' : 'pending',
        ]);

        Log::info('Subscription renewed.', [
            'user_id'         => $userId,
            'subscription_id' => $subscription->id,
            'plan'            => $plan->name,
        ]);

        return $subscription->load('plan');
    }

    // ─── Queries ─────────────────────────────────────────────────

    /**
     * Get user's current active subscription with plan details.
     *
     * @param int $userId
     * @return UserSubscription|null
     */
    public function getCurrentSubscription(int $userId): ?UserSubscription
    {
        return UserSubscription::where('user_id', $userId)
            ->active()
            ->with('plan')
            ->first();
    }

    /**
     * Get user's billing/payment history (paginated).
     *
     * @param int $userId
     * @param int $perPage
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getBillingHistory(int $userId, int $perPage = 15)
    {
        return PaymentTransaction::where('user_id', $userId)
            ->with('subscription.plan:id,name,slug')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    // ─── Usage Limit Checks ──────────────────────────────────────

    /**
     * Check if user has exceeded a resource limit.
     *
     * @param int    $userId
     * @param string $resource  leads|messages|orders
     * @return array{allowed: bool, current: int, limit: int}
     */
    public function checkUsageLimit(int $userId, string $resource): array
    {
        $subscription = $this->getCurrentSubscription($userId);

        if (!$subscription || !$subscription->plan) {
            return [
                'allowed' => false,
                'current' => 0,
                'limit'   => 0,
                'message' => 'No active subscription.',
            ];
        }

        $plan    = $subscription->plan;
        $current = $this->getCurrentUsage($userId, $resource);
        $limit   = $this->getPlanLimit($plan, $resource);

        return [
            'allowed' => $current < $limit,
            'current' => $current,
            'limit'   => $limit,
        ];
    }

    /**
     * Get current usage count for a resource.
     */
    private function getCurrentUsage(int $userId, string $resource): int
    {
        return match ($resource) {
            'leads'    => Lead::where('user_id', $userId)->count(),
            'messages' => Message::whereHas('lead', fn ($q) => $q->where('user_id', $userId))->count(),
            'orders'   => Order::whereHas('lead', fn ($q) => $q->where('user_id', $userId))->count(),
            default    => 0,
        };
    }

    /**
     * Get the plan's limit for a resource.
     */
    private function getPlanLimit(SubscriptionPlan $plan, string $resource): int
    {
        return match ($resource) {
            'leads'    => $plan->max_leads,
            'messages' => $plan->max_messages,
            'orders'   => $plan->max_orders,
            default    => 0,
        };
    }

    // ─── Webhook Handling ────────────────────────────────────────

    /**
     * Handle Razorpay payment webhook.
     *
     * @param array $payload
     * @return void
     */
    public function handleRazorpayWebhook(array $payload): void
    {
        $event = $payload['event'] ?? null;

        Log::info('Razorpay webhook received.', ['event' => $event]);

        if ($event === 'payment.captured') {
            $payment       = $payload['payload']['payment']['entity'] ?? [];
            $transactionId = $payment['id'] ?? null;
            $orderId       = $payment['order_id'] ?? null;

            $this->processSuccessfulPayment('razorpay', $transactionId, $orderId, $payment);

        } elseif ($event === 'payment.failed') {
            $payment       = $payload['payload']['payment']['entity'] ?? [];
            $transactionId = $payment['id'] ?? null;
            $orderId       = $payment['order_id'] ?? null;

            $this->processFailedPayment('razorpay', $transactionId, $orderId, $payment);
        }
    }

    /**
     * Handle Stripe payment webhook.
     *
     * @param array $payload
     * @return void
     */
    public function handleStripeWebhook(array $payload): void
    {
        $event = $payload['type'] ?? null;

        Log::info('Stripe webhook received.', ['event' => $event]);

        if ($event === 'checkout.session.completed' || $event === 'payment_intent.succeeded') {
            $data          = $payload['data']['object'] ?? [];
            $transactionId = $data['payment_intent'] ?? $data['id'] ?? null;
            $paymentId     = $data['id'] ?? null;

            $this->processSuccessfulPayment('stripe', $transactionId, $paymentId, $data);

        } elseif ($event === 'payment_intent.payment_failed') {
            $data          = $payload['data']['object'] ?? [];
            $transactionId = $data['id'] ?? null;
            $paymentId     = $data['id'] ?? null;

            $this->processFailedPayment('stripe', $transactionId, $paymentId, $data);
        }
    }

    /**
     * Process a successful payment from any gateway.
     */
    private function processSuccessfulPayment(string $gateway, ?string $transactionId, ?string $paymentId, array $rawPayload): void
    {
        try {
            // Find pending subscription by payment_id
            $subscription = UserSubscription::where('payment_gateway', $gateway)
                ->where('payment_id', $paymentId)
                ->where('status', 'pending')
                ->first();

            if (!$subscription) {
                Log::warning('Webhook: No pending subscription found.', [
                    'gateway'    => $gateway,
                    'payment_id' => $paymentId,
                ]);
                return;
            }

            DB::beginTransaction();

            // Activate subscription
            $subscription->update(['status' => 'active']);

            // Update or create payment transaction
            PaymentTransaction::updateOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'gateway'         => $gateway,
                ],
                [
                    'user_id'          => $subscription->user_id,
                    'transaction_id'   => $transactionId,
                    'amount'           => $subscription->amount,
                    'currency'         => 'INR',
                    'status'           => 'success',
                    'response_payload' => $rawPayload,
                ]
            );

            DB::commit();

            Log::info('Payment processed successfully via webhook.', [
                'gateway'         => $gateway,
                'subscription_id' => $subscription->id,
                'user_id'         => $subscription->user_id,
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Webhook payment processing failed.', [
                'gateway' => $gateway,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process a failed payment from any gateway.
     */
    private function processFailedPayment(string $gateway, ?string $transactionId, ?string $paymentId, array $rawPayload): void
    {
        try {
            $subscription = UserSubscription::where('payment_gateway', $gateway)
                ->where('payment_id', $paymentId)
                ->where('status', 'pending')
                ->first();

            if (!$subscription) {
                Log::warning('Webhook: No pending subscription found for failed payment.', [
                    'gateway'    => $gateway,
                    'payment_id' => $paymentId,
                ]);
                return;
            }

            DB::beginTransaction();

            // Mark subscription as cancelled
            $subscription->update(['status' => 'cancelled']);

            // Record failed transaction
            PaymentTransaction::updateOrCreate(
                [
                    'subscription_id' => $subscription->id,
                    'gateway'         => $gateway,
                ],
                [
                    'user_id'          => $subscription->user_id,
                    'transaction_id'   => $transactionId,
                    'amount'           => $subscription->amount,
                    'currency'         => 'INR',
                    'status'           => 'failed',
                    'response_payload' => $rawPayload,
                ]
            );

            DB::commit();

            Log::warning('Payment failed via webhook.', [
                'gateway'         => $gateway,
                'subscription_id' => $subscription->id,
                'user_id'         => $subscription->user_id,
            ]);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Webhook failed payment processing error.', [
                'gateway' => $gateway,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    // ─── Auto-Expiry ─────────────────────────────────────────────

    /**
     * Expire all overdue subscriptions.
     * Called by the scheduled command: subscriptions:expire
     *
     * @return int Number of subscriptions expired
     */
    public function expireOverdueSubscriptions(): int
    {
        $count = UserSubscription::where('status', 'active')
            ->where('expiry_date', '<=', Carbon::now())
            ->update(['status' => 'expired']);

        if ($count > 0) {
            Log::info("Auto-expired {$count} overdue subscription(s).");
        }

        return $count;
    }
}
