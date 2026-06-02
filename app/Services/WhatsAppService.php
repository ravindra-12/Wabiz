<?php

namespace App\Services;

use App\Models\WhatsAppAccount;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class WhatsAppService
{
    /**
     * Send a WhatsApp text message using tenant credentials.
     *
     * @param WhatsAppAccount $account
     * @param string $phone
     * @param string $messageText
     * @return array{success: bool, data?: array, error?: mixed}
     */
    public function sendMessage(WhatsAppAccount $account, string $phone, string $messageText): array
    {
        try {
            $accessToken = $account->access_token;
            $phoneNumberId = $account->phone_number_id;
            $apiVersion = $account->api_version ?? 'v21.0';

            if (!$accessToken || !$phoneNumberId) {
                Log::error('WhatsApp credentials incomplete.', [
                    'account_id' => $account->id,
                    'user_id' => $account->user_id,
                ]);

                return [
                    'success' => false,
                    'error' => 'WhatsApp account credentials are incomplete',
                ];
            }

            $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

            $response = Http::withToken($accessToken)
                ->timeout(30)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $phone,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $messageText,
                    ],
                ]);

            if ($response->successful()) {
                Log::info('WhatsApp message sent.', [
                    'phone' => $phone,
                    'user_id' => $account->user_id,
                ]);

                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::error('WhatsApp API error.', [
                'phone' => $phone,
                'user_id' => $account->user_id,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return [
                'success' => false,
                'error' => $response->json(),
            ];

        } catch (Exception $e) {
            Log::error('WhatsApp API exception: ' . $e->getMessage(), [
                'account_id' => $account->id,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a WhatsApp message and save it to the messages table.
     *
     * @param WhatsAppAccount $account
     * @param int $leadId
     * @param string $phone
     * @param string $messageText
     * @return bool
     */
    public function sendAndSave(WhatsAppAccount $account, int $leadId, string $phone, string $messageText): bool
    {
        $result = $this->sendMessage($account, $phone, $messageText);

        if ($result['success']) {
            Message::create([
                'lead_id' => $leadId,
                'message' => $messageText,
                'type' => 'outgoing',
                'status' => 'sent',
            ]);

            return true;
        }

        return false;
    }
}
