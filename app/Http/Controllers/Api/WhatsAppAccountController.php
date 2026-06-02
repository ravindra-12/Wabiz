<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\WhatsAppAccount;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class WhatsAppAccountController extends Controller
{
    /**
     * Connect WhatsApp account
     *
     * @OA\Post(
     *     path="/whatsapp-account",
     *     tags={"WhatsApp Account"},
     *     summary="Connect WhatsApp account",
     *     description="Connect a WhatsApp Cloud API account for the authenticated user. Each user can have only one WhatsApp account.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"phone_number_id", "access_token", "verify_token"},
     *             @OA\Property(property="phone_number_id", type="string", example="102345678901234"),
     *             @OA\Property(property="access_token", type="string", example="EAAxxxxxxxxx..."),
     *             @OA\Property(property="business_account_id", type="string", nullable=true, example="109876543210"),
     *             @OA\Property(property="verify_token", type="string", example="my_custom_verify_token"),
     *             @OA\Property(property="api_version", type="string", example="v21.0")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="WhatsApp account connected successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="WhatsApp account connected successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Account already connected",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=false),
     *             @OA\Property(property="message", type="string", example="You already have a WhatsApp account connected")
     *         )
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function connect(Request $request)
    {
        try {
            $userId = auth()->id();

            // Check if user already has a WhatsApp account
            $existing = WhatsAppAccount::where('user_id', $userId)->first();

            if ($existing) {
                return response()->json([
                    'status' => false,
                    'message' => 'You already have a WhatsApp account connected. Use the update endpoint to modify credentials.'
                ], 409);
            }

            $validator = Validator::make($request->all(), [
                'phone_number_id' => 'required|string|max:50|unique:whatsapp_accounts,phone_number_id',
                'access_token' => 'required|string',
                'business_account_id' => 'nullable|string|max:50',
                'verify_token' => 'required|string|max:255',
                'api_version' => 'nullable|string|max:10',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $account = WhatsAppAccount::create([
                'user_id' => $userId,
                'phone_number_id' => $request->phone_number_id,
                'access_token' => $request->access_token,
                'business_account_id' => $request->business_account_id,
                'verify_token' => $request->verify_token,
                'api_version' => $request->api_version ?? 'v21.0',
                'is_active' => true,
            ]);

            DB::commit();

            Log::info('WhatsApp account connected.', [
                'user_id' => $userId,
                'phone_number_id' => $request->phone_number_id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp account connected successfully',
                'data' => $account
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error connecting WhatsApp account: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error connecting WhatsApp account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get connected WhatsApp account
     *
     * @OA\Get(
     *     path="/whatsapp-account",
     *     tags={"WhatsApp Account"},
     *     summary="Get connected WhatsApp account",
     *     description="Get the WhatsApp account details for the authenticated user",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="WhatsApp account fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="WhatsApp account fetched successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No WhatsApp account connected",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function show()
    {
        try {
            $account = WhatsAppAccount::where('user_id', auth()->id())->first();

            if (!$account) {
                return response()->json([
                    'status' => false,
                    'message' => 'No WhatsApp account connected'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp account fetched successfully',
                'data' => $account
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching WhatsApp account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update WhatsApp credentials
     *
     * @OA\Put(
     *     path="/whatsapp-account",
     *     tags={"WhatsApp Account"},
     *     summary="Update WhatsApp credentials",
     *     description="Update the WhatsApp Cloud API credentials for the authenticated user",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="phone_number_id", type="string", example="102345678901234"),
     *             @OA\Property(property="access_token", type="string", example="EAAxxxxxxxxx..."),
     *             @OA\Property(property="business_account_id", type="string", nullable=true),
     *             @OA\Property(property="verify_token", type="string"),
     *             @OA\Property(property="api_version", type="string", example="v21.0"),
     *             @OA\Property(property="is_active", type="boolean", example=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="WhatsApp account updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="WhatsApp account updated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No WhatsApp account connected",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *         @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function update(Request $request)
    {
        try {
            $userId = auth()->id();

            $account = WhatsAppAccount::where('user_id', $userId)->first();

            if (!$account) {
                return response()->json([
                    'status' => false,
                    'message' => 'No WhatsApp account connected'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'phone_number_id' => 'sometimes|string|max:50|unique:whatsapp_accounts,phone_number_id,' . $account->id,
                'access_token' => 'sometimes|string',
                'business_account_id' => 'nullable|string|max:50',
                'verify_token' => 'sometimes|string|max:255',
                'api_version' => 'nullable|string|max:10',
                'is_active' => 'sometimes|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $account->update($request->only([
                'phone_number_id',
                'access_token',
                'business_account_id',
                'verify_token',
                'api_version',
                'is_active',
            ]));

            DB::commit();

            Log::info('WhatsApp account updated.', [
                'user_id' => $userId,
                'account_id' => $account->id,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp account updated successfully',
                'data' => $account->fresh()
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error updating WhatsApp account: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error updating WhatsApp account',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Disconnect WhatsApp account
     *
     * @OA\Delete(
     *     path="/whatsapp-account",
     *     tags={"WhatsApp Account"},
     *     summary="Disconnect WhatsApp account",
     *     description="Disconnect and remove the WhatsApp Cloud API account for the authenticated user",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="WhatsApp account disconnected successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="WhatsApp account disconnected successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="No WhatsApp account connected",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function disconnect()
    {
        try {
            $userId = auth()->id();

            $account = WhatsAppAccount::where('user_id', $userId)->first();

            if (!$account) {
                return response()->json([
                    'status' => false,
                    'message' => 'No WhatsApp account connected'
                ], 404);
            }

            DB::beginTransaction();

            $phoneNumberId = $account->phone_number_id;
            $account->delete();

            DB::commit();

            Log::info('WhatsApp account disconnected.', [
                'user_id' => $userId,
                'phone_number_id' => $phoneNumberId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'WhatsApp account disconnected successfully'
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error disconnecting WhatsApp account: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error disconnecting WhatsApp account',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
