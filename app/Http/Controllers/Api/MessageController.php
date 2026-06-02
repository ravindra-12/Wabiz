<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Message;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class MessageController extends Controller
{
    /**
     * Get messages by lead
     *
     * @OA\Get(
     *     path="/messages/{lead_id}",
     *     tags={"Messages"},
     *     summary="Get messages by lead",
     *     description="Retrieve all messages for a specific lead owned by the authenticated user, with pagination and optional type filter",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="lead_id",
     *         in="path",
     *         description="Lead ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="type",
     *         in="query",
     *         description="Filter by message type",
     *         required=false,
     *         @OA\Schema(type="string", enum={"incoming", "outgoing"})
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Records per page (default: 20)",
     *         required=false,
     *         @OA\Schema(type="integer", example=20)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Messages fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Messages fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="lead",
     *                     type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="name", type="string"),
     *                     @OA\Property(property="phone", type="string")
     *                 ),
     *                 @OA\Property(
     *                     property="messages",
     *                     type="object",
     *                     @OA\Property(property="data", type="array", @OA\Items(ref="#/components/schemas/Message")),
     *                     @OA\Property(property="current_page", type="integer"),
     *                     @OA\Property(property="per_page", type="integer"),
     *                     @OA\Property(property="total", type="integer")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function getByLead(Request $request, $lead_id)
    {
        try {
            // Multi-tenant: only fetch leads owned by authenticated user
            $lead = Lead::where('id', $lead_id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$lead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            $query = Message::where('lead_id', $lead_id)
                ->orderBy('created_at', 'asc');

            // Optional filter by type
            if ($request->has('type')) {
                $validator = Validator::make($request->only('type'), [
                    'type' => 'in:incoming,outgoing'
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Validation error',
                        'errors' => $validator->errors()
                    ], 422);
                }

                $query->where('type', $request->type);
            }

            $messages = $query->paginate($request->get('per_page', 20));

            return response()->json([
                'status' => true,
                'message' => 'Messages fetched successfully',
                'data' => [
                    'lead' => [
                        'id' => $lead->id,
                        'name' => $lead->name,
                        'phone' => $lead->phone,
                    ],
                    'messages' => $messages,
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching messages',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send a WhatsApp message
     *
     * @OA\Post(
     *     path="/messages/send",
     *     tags={"Messages"},
     *     summary="Send a WhatsApp message",
     *     description="Send a text message to a lead via WhatsApp Cloud API using the authenticated user's WhatsApp account credentials",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "message"},
     *             @OA\Property(property="lead_id", type="integer", example=1),
     *             @OA\Property(property="message", type="string", example="Hello! How can we help you?")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Message sent successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Message sent successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Message")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Lead not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=502,
     *         description="WhatsApp API error",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="Failed to send message via WhatsApp"),
     *             @OA\Property(property="whatsapp_error", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function send(Request $request)
    {
        try {
            $userId = auth()->id();

            $validator = Validator::make($request->all(), [
                'lead_id' => 'required|integer|exists:leads,id',
                'message' => 'required|string|max:4096',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Multi-tenant: only access leads owned by authenticated user
            $lead = Lead::where('id', $request->lead_id)
                ->where('user_id', $userId)
                ->first();

            if (!$lead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            // Load the authenticated user's WhatsApp account credentials
            $whatsappAccount = WhatsAppAccount::where('user_id', $userId)
                ->where('is_active', true)
                ->first();

            if (!$whatsappAccount) {
                return response()->json([
                    'status' => false,
                    'message' => 'No active WhatsApp account connected. Please connect your WhatsApp account first.'
                ], 403);
            }

            // Send message via WhatsApp Cloud API using tenant credentials
            $whatsappResponse = $this->sendWhatsAppMessage(
                $whatsappAccount,
                $lead->phone,
                $request->message
            );

            if (!$whatsappResponse['success']) {
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to send message via WhatsApp',
                    'whatsapp_error' => $whatsappResponse['error'] ?? null,
                ], 502);
            }

            DB::beginTransaction();

            // Save outgoing message
            $message = Message::create([
                'lead_id' => $lead->id,
                'message' => $request->message,
                'type' => 'outgoing',
                'status' => 'sent',
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Message sent successfully',
                'data' => $message
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error sending WhatsApp message: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'lead_id' => $request->lead_id ?? null,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error sending message',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Send message via WhatsApp Cloud API using tenant credentials
     *
     * @param WhatsAppAccount $account
     * @param string $phone
     * @param string $messageText
     * @return array
     */
    private function sendWhatsAppMessage(WhatsAppAccount $account, string $phone, string $messageText): array
    {
        try {
            $accessToken = $account->access_token;
            $phoneNumberId = $account->phone_number_id;
            $apiVersion = $account->api_version ?? 'v21.0';

            if (!$accessToken || !$phoneNumberId) {
                Log::error('WhatsApp account has missing credentials.', [
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
                Log::info('WhatsApp message sent successfully.', [
                    'phone' => $phone,
                    'user_id' => $account->user_id,
                    'wa_response' => $response->json(),
                ]);

                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            }

            Log::error('WhatsApp API returned error.', [
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
            Log::error('WhatsApp API request failed: ' . $e->getMessage(), [
                'user_id' => $account->user_id,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
