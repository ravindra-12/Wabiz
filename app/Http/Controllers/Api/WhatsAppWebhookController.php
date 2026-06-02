<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verify WhatsApp Webhook (GET)
     *
     * Multi-tenant: Checks verify_token against ALL active whatsapp_accounts.
     * Meta sends a GET request to verify the webhook URL.
     * It sends hub.mode, hub.verify_token, and hub.challenge.
     * We must respond with hub.challenge if the token matches any tenant.
     *
     * @OA\Get(
     *     path="/webhook/whatsapp",
     *     tags={"WhatsApp Webhook"},
     *     summary="Verify WhatsApp webhook",
     *     description="Handles Meta webhook verification challenge. Returns hub.challenge if verify_token matches any connected WhatsApp account.",
     *     @OA\Parameter(
     *         name="hub.mode",
     *         in="query",
     *         description="Should be 'subscribe'",
     *         required=true,
     *         @OA\Schema(type="string", example="subscribe")
     *     ),
     *     @OA\Parameter(
     *         name="hub.verify_token",
     *         in="query",
     *         description="The verify token set in Meta App Dashboard",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="hub.challenge",
     *         in="query",
     *         description="Challenge string to echo back",
     *         required=true,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Webhook verified successfully — returns challenge string",
     *         @OA\JsonContent(type="integer", example=1234567890)
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Verification failed — token mismatch",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Webhook verification failed")
     *         )
     *     )
     * )
     */
    public function verify(Request $request)
    {
        try {
            $mode = $request->query('hub_mode');
            $token = $request->query('hub_verify_token');
            $challenge = $request->query('hub_challenge');

            if ($mode !== 'subscribe' || !$token) {
                return response()->json([
                    'status' => false,
                    'message' => 'Webhook verification failed'
                ], 403);
            }

            // Multi-tenant: find any active account matching this verify_token
            $account = WhatsAppAccount::where('verify_token', $token)
                ->where('is_active', true)
                ->first();

            if ($account) {
                Log::info('WhatsApp Webhook verified successfully.', [
                    'user_id' => $account->user_id,
                    'phone_number_id' => $account->phone_number_id,
                ]);

                return response($challenge, 200)->header('Content-Type', 'text/plain');
            }

            Log::warning('WhatsApp Webhook verification failed — no matching verify_token.', [
                'mode' => $mode,
                'token_received' => $token,
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Webhook verification failed'
            ], 403);

        } catch (Exception $e) {
            Log::error('WhatsApp Webhook verify error: ' . $e->getMessage());

            return response()->json([
                'status' => false,
                'message' => 'Webhook verification error',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle Incoming WhatsApp Messages (POST)
     *
     * Multi-tenant flow:
     * 1. Parse Meta Cloud API payload
     * 2. Extract phone_number_id from metadata
     * 3. Find matching whatsapp_account → identify tenant (user)
     * 4. Find or create lead scoped to that tenant
     * 5. Save incoming message
     *
     * @OA\Post(
     *     path="/webhook/whatsapp",
     *     tags={"WhatsApp Webhook"},
     *     summary="Handle incoming WhatsApp messages",
     *     description="Receives incoming WhatsApp messages from Meta Cloud API. Routes to correct tenant via phone_number_id, auto-creates lead if phone number is new for that tenant, and saves the message.",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="object", type="string", example="whatsapp_business_account"),
     *             @OA\Property(
     *                 property="entry",
     *                 type="array",
     *                 @OA\Items(
     *                     type="object",
     *                     @OA\Property(property="id", type="string"),
     *                     @OA\Property(
     *                         property="changes",
     *                         type="array",
     *                         @OA\Items(
     *                             type="object",
     *                             @OA\Property(
     *                                 property="value",
     *                                 type="object",
     *                                 @OA\Property(property="messaging_product", type="string"),
     *                                 @OA\Property(
     *                                     property="metadata",
     *                                     type="object",
     *                                     @OA\Property(property="phone_number_id", type="string"),
     *                                     @OA\Property(property="display_phone_number", type="string")
     *                                 ),
     *                                 @OA\Property(
     *                                     property="contacts",
     *                                     type="array",
     *                                     @OA\Items(
     *                                         type="object",
     *                                         @OA\Property(property="wa_id", type="string"),
     *                                         @OA\Property(
     *                                             property="profile",
     *                                             type="object",
     *                                             @OA\Property(property="name", type="string")
     *                                         )
     *                                     )
     *                                 ),
     *                                 @OA\Property(
     *                                     property="messages",
     *                                     type="array",
     *                                     @OA\Items(
     *                                         type="object",
     *                                         @OA\Property(property="from", type="string"),
     *                                         @OA\Property(property="type", type="string"),
     *                                         @OA\Property(
     *                                             property="text",
     *                                             type="object",
     *                                             @OA\Property(property="body", type="string")
     *                                         )
     *                                     )
     *                                 )
     *                             ),
     *                             @OA\Property(property="field", type="string")
     *                         )
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message processed successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Message processed successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function handleIncoming(Request $request)
    {
        try {
            $payload = $request->all();

            Log::info('WhatsApp Webhook payload received.', ['payload' => $payload]);

            // Validate this is a WhatsApp Business Account event
            if (($payload['object'] ?? null) !== 'whatsapp_business_account') {
                Log::info('Non-WhatsApp event received, ignoring.');

                return response()->json([
                    'status' => true,
                    'message' => 'Event ignored'
                ], 200);
            }

            // Process each entry
            $entries = $payload['entry'] ?? [];

            foreach ($entries as $entry) {
                $changes = $entry['changes'] ?? [];

                foreach ($changes as $change) {
                    $value = $change['value'] ?? [];

                    // Only process message events
                    if (($change['field'] ?? '') !== 'messages') {
                        continue;
                    }

                    // ── Multi-tenant: identify the tenant via phone_number_id ──
                    $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                    if (!$phoneNumberId) {
                        Log::warning('Webhook payload missing phone_number_id in metadata, skipping.');
                        continue;
                    }

                    $whatsappAccount = WhatsAppAccount::where('phone_number_id', $phoneNumberId)
                        ->where('is_active', true)
                        ->first();

                    if (!$whatsappAccount) {
                        Log::warning('No active WhatsApp account found for phone_number_id.', [
                            'phone_number_id' => $phoneNumberId,
                        ]);
                        continue;
                    }

                    $tenantUserId = $whatsappAccount->user_id;

                    // Process status updates (delivered, read, etc.)
                    if (isset($value['statuses'])) {
                        $this->processStatusUpdates($value['statuses'], $tenantUserId);
                        continue;
                    }

                    // Process incoming messages
                    $messages = $value['messages'] ?? [];
                    $contacts = $value['contacts'] ?? [];

                    foreach ($messages as $index => $incomingMessage) {
                        $senderPhone = $incomingMessage['from'] ?? null;
                        $messageType = $incomingMessage['type'] ?? 'text';
                        $messageText = $this->extractMessageText($incomingMessage);
                        $senderName = $contacts[$index]['profile']['name'] ?? null;

                        if (!$senderPhone || !$messageText) {
                            Log::warning('Incomplete message data, skipping.', [
                                'phone' => $senderPhone,
                                'message' => $messageText,
                                'tenant_user_id' => $tenantUserId,
                            ]);
                            continue;
                        }

                        // Normalize phone number (remove leading '+' if present)
                        $senderPhone = ltrim($senderPhone, '+');

                        DB::beginTransaction();

                        try {
                            // Find or create lead scoped to this tenant
                            $lead = Lead::where('user_id', $tenantUserId)
                                ->where('phone', $senderPhone)
                                ->first();

                            if (!$lead) {
                                $lead = Lead::create([
                                    'user_id' => $tenantUserId,
                                    'name' => $senderName ?? 'WhatsApp User',
                                    'phone' => $senderPhone,
                                    'source' => 'whatsapp',
                                    'status' => 'new',
                                ]);

                                Log::info('New lead created from WhatsApp.', [
                                    'lead_id' => $lead->id,
                                    'phone' => $senderPhone,
                                    'tenant_user_id' => $tenantUserId,
                                ]);
                            }

                            // Save incoming message
                            $message = Message::create([
                                'lead_id' => $lead->id,
                                'message' => $messageText,
                                'type' => 'incoming',
                                'status' => 'delivered',
                            ]);

                            DB::commit();

                            Log::info('Incoming WhatsApp message saved.', [
                                'message_id' => $message->id,
                                'lead_id' => $lead->id,
                                'tenant_user_id' => $tenantUserId,
                            ]);

                        } catch (Exception $e) {
                            DB::rollBack();

                            Log::error('Failed to process WhatsApp message.', [
                                'phone' => $senderPhone,
                                'tenant_user_id' => $tenantUserId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // Always return 200 to Meta to avoid retries
            return response()->json([
                'status' => true,
                'message' => 'Message processed successfully'
            ], 200);

        } catch (Exception $e) {
            Log::error('WhatsApp Webhook error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            // Still return 200 to prevent Meta from retrying
            return response()->json([
                'status' => true,
                'message' => 'Acknowledged'
            ], 200);
        }
    }

    /**
     * Extract message text from different message types
     *
     * @param array $message
     * @return string|null
     */
    private function extractMessageText(array $message): ?string
    {
        $type = $message['type'] ?? 'text';

        return match ($type) {
            'text' => $message['text']['body'] ?? null,
            'image' => '[Image] ' . ($message['image']['caption'] ?? ''),
            'video' => '[Video] ' . ($message['video']['caption'] ?? ''),
            'audio' => '[Audio message]',
            'document' => '[Document] ' . ($message['document']['filename'] ?? ''),
            'sticker' => '[Sticker]',
            'location' => '[Location] Lat: ' . ($message['location']['latitude'] ?? '') . ', Lng: ' . ($message['location']['longitude'] ?? ''),
            'contacts' => '[Contact shared]',
            'reaction' => '[Reaction] ' . ($message['reaction']['emoji'] ?? ''),
            'button' => $message['button']['text'] ?? null,
            'interactive' => $this->extractInteractiveText($message),
            default => '[Unsupported message type: ' . $type . ']',
        };
    }

    /**
     * Extract text from interactive messages (list replies, button replies)
     *
     * @param array $message
     * @return string|null
     */
    private function extractInteractiveText(array $message): ?string
    {
        $interactive = $message['interactive'] ?? [];
        $interactiveType = $interactive['type'] ?? '';

        return match ($interactiveType) {
            'button_reply' => $interactive['button_reply']['title'] ?? null,
            'list_reply' => $interactive['list_reply']['title'] ?? null,
            default => '[Interactive: ' . $interactiveType . ']',
        };
    }

    /**
     * Process message status updates (sent, delivered, read)
     *
     * @param array $statuses
     * @param int $tenantUserId
     * @return void
     */
    private function processStatusUpdates(array $statuses, int $tenantUserId): void
    {
        foreach ($statuses as $statusUpdate) {
            $waMessageId = $statusUpdate['id'] ?? null;
            $status = $statusUpdate['status'] ?? null;

            if (!$waMessageId || !$status) {
                continue;
            }

            Log::info('WhatsApp message status update.', [
                'wa_message_id' => $waMessageId,
                'status' => $status,
                'tenant_user_id' => $tenantUserId,
            ]);

            // Future: Update message status in DB if you store wa_message_id
            // Message::where('wa_message_id', $waMessageId)->update(['status' => $status]);
        }
    }
}
