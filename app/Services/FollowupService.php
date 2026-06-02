<?php

namespace App\Services;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class FollowupService
{
    /**
     * Send a single follow-up message via WhatsApp Cloud API.
     *
     * This method:
     * 1. Resolves the lead's owner (tenant)
     * 2. Loads the tenant's WhatsApp account credentials
     * 3. Sends the message via Meta Cloud API
     * 4. Saves the outgoing message in the messages table
     * 5. Updates the follow-up status (sent / failed)
     *
     * @param Followup $followup
     * @return bool
     */
    public function sendFollowup(Followup $followup): bool
    {
        // Load lead with owner
        $lead = Lead::with('owner')->find($followup->lead_id);

        if (!$lead) {
            Log::error('Followup failed: Lead not found.', [
                'followup_id' => $followup->id,
                'lead_id' => $followup->lead_id,
            ]);

            $followup->update(['status' => 'failed']);
            return false;
        }

        $tenantUserId = $lead->user_id;

        if (!$tenantUserId) {
            Log::error('Followup failed: Lead has no owner (user_id is null).', [
                'followup_id' => $followup->id,
                'lead_id' => $lead->id,
            ]);

            $followup->update(['status' => 'failed']);
            return false;
        }

        // Load the tenant's active WhatsApp account
        $whatsappAccount = WhatsAppAccount::where('user_id', $tenantUserId)
            ->where('is_active', true)
            ->first();

        if (!$whatsappAccount) {
            Log::error('Followup failed: No active WhatsApp account for tenant.', [
                'followup_id' => $followup->id,
                'tenant_user_id' => $tenantUserId,
            ]);

            $followup->update(['status' => 'failed']);
            return false;
        }

        // Send via WhatsApp Cloud API
        $apiResponse = $this->callWhatsAppApi($whatsappAccount, $lead->phone, $followup->message);

        DB::beginTransaction();

        try {
            if ($apiResponse['success']) {
                // Mark follow-up as sent
                $followup->update(['status' => 'sent']);

                // Save outgoing message in messages table
                Message::create([
                    'lead_id' => $lead->id,
                    'message' => $followup->message,
                    'type' => 'outgoing',
                    'status' => 'sent',
                ]);

                DB::commit();

                Log::info('Followup sent successfully.', [
                    'followup_id' => $followup->id,
                    'lead_id' => $lead->id,
                    'tenant_user_id' => $tenantUserId,
                ]);

                return true;
            }

            // Mark follow-up as failed
            $followup->update(['status' => 'failed']);

            DB::commit();

            Log::error('Followup WhatsApp API call failed.', [
                'followup_id' => $followup->id,
                'lead_id' => $lead->id,
                'tenant_user_id' => $tenantUserId,
                'error' => $apiResponse['error'] ?? 'Unknown error',
            ]);

            return false;

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Followup DB transaction failed.', [
                'followup_id' => $followup->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Call the WhatsApp Cloud API to send a text message.
     *
     * @param WhatsAppAccount $account
     * @param string $phone
     * @param string $messageText
     * @return array{success: bool, data?: array, error?: mixed}
     */
    private function callWhatsAppApi(WhatsAppAccount $account, string $phone, string $messageText): array
    {
        try {
            $accessToken = $account->access_token;
            $phoneNumberId = $account->phone_number_id;
            $apiVersion = $account->api_version ?? 'v21.0';

            if (!$accessToken || !$phoneNumberId) {
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
                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            return [
                'success' => false,
                'error' => $response->json(),
            ];

        } catch (Exception $e) {
            Log::error('WhatsApp API request exception in FollowupService.', [
                'error' => $e->getMessage(),
                'account_id' => $account->id,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
