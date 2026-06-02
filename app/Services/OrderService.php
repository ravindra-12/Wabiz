<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Order;
use App\Models\WhatsAppAccount;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class OrderService
{
    protected WhatsAppService $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Send a WhatsApp notification for order status changes.
     *
     * Only sends for: created, shipped, delivered.
     *
     * @param Order $order
     * @param string $event  created|shipped|delivered
     * @return void
     */
    public function sendOrderNotification(Order $order, string $event): void
    {
        try {
            $lead = Lead::find($order->lead_id);

            if (!$lead || !$lead->user_id) {
                Log::warning('Order notification skipped — lead or tenant not found.', [
                    'order_id' => $order->id,
                    'event' => $event,
                ]);
                return;
            }

            $whatsappAccount = WhatsAppAccount::where('user_id', $lead->user_id)
                ->where('is_active', true)
                ->first();

            if (!$whatsappAccount) {
                Log::warning('Order notification skipped — no active WhatsApp account.', [
                    'order_id' => $order->id,
                    'tenant_user_id' => $lead->user_id,
                ]);
                return;
            }

            $message = $this->buildNotificationMessage($order, $lead, $event);

            $this->whatsAppService->sendAndSave(
                $whatsappAccount,
                $lead->id,
                $lead->phone,
                $message
            );

            Log::info('Order WhatsApp notification sent.', [
                'order_id' => $order->id,
                'event' => $event,
                'lead_id' => $lead->id,
            ]);

        } catch (\Exception $e) {
            // Non-blocking — notification failure should not break order flow
            Log::error('Order notification failed.', [
                'order_id' => $order->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build the notification message text based on event type.
     */
    private function buildNotificationMessage(Order $order, Lead $lead, string $event): string
    {
        $customerName = $lead->name ?? 'Customer';
        $orderId = $order->id;
        $total = number_format($order->total_price, 2);

        return match ($event) {
            'created' => "Hi {$customerName}! 🛒\n\nYour order #{$orderId} has been placed successfully.\nTotal: Rs. {$total}\n\nWe'll keep you updated on the status. Thank you!",
            'shipped' => "Hi {$customerName}! 📦\n\nGreat news! Your order #{$orderId} has been shipped.\nTotal: Rs. {$total}\n\nIt's on the way to you!",
            'delivered' => "Hi {$customerName}! ✅\n\nYour order #{$orderId} has been delivered.\nTotal: Rs. {$total}\n\nThank you for your purchase! We hope you love it.",
            default => "Hi {$customerName}!\n\nYour order #{$orderId} status has been updated to: {$event}.\nTotal: Rs. {$total}",
        };
    }
}
