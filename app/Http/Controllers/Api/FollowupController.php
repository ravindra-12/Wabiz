<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\SendFollowupJob;
use App\Models\Followup;
use App\Models\Lead;
use App\Services\FollowupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class FollowupController extends Controller
{
    protected FollowupService $followupService;

    public function __construct(FollowupService $followupService)
    {
        $this->followupService = $followupService;
    }

    /**
     * Get all follow-ups
     *
     * @OA\Get(
     *     path="/followups",
     *     tags={"Followups"},
     *     summary="Get all follow-ups",
     *     description="Retrieve all follow-ups for leads owned by the authenticated user with optional filtering and pagination",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by follow-up status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "sent", "failed"})
     *     ),
     *     @OA\Parameter(
     *         name="lead_id",
     *         in="query",
     *         description="Filter by lead ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Records per page (default: 15)",
     *         required=false,
     *         @OA\Schema(type="integer", example=15)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Follow-ups fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Follow-ups fetched successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function index(Request $request)
    {
        try {
            $userId = auth()->id();

            // Multi-tenant: only follow-ups for leads owned by this user
            $query = Followup::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->with('lead:id,name,phone');

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by lead_id (verify ownership)
            if ($request->has('lead_id')) {
                $leadExists = Lead::where('id', $request->lead_id)
                    ->where('user_id', $userId)
                    ->exists();

                if (!$leadExists) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Lead not found'
                    ], 404);
                }

                $query->where('lead_id', $request->lead_id);
            }

            $followups = $query->orderBy('scheduled_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => true,
                'message' => 'Follow-ups fetched successfully',
                'data' => $followups
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching follow-ups',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get follow-ups by lead
     *
     * @OA\Get(
     *     path="/followups/lead/{lead_id}",
     *     tags={"Followups"},
     *     summary="Get follow-ups by lead",
     *     description="Retrieve all follow-ups for a specific lead owned by the authenticated user",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="lead_id",
     *         in="path",
     *         description="Lead ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"pending", "sent", "failed"})
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Follow-ups fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Follow-ups fetched successfully"),
     *             @OA\Property(property="data", type="object")
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
            $lead = Lead::where('id', $lead_id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$lead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            $query = Followup::where('lead_id', $lead_id)
                ->orderBy('scheduled_at', 'desc');

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $followups = $query->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => true,
                'message' => 'Follow-ups fetched successfully',
                'data' => [
                    'lead' => [
                        'id' => $lead->id,
                        'name' => $lead->name,
                        'phone' => $lead->phone,
                    ],
                    'followups' => $followups,
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching follow-ups',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create follow-up
     *
     * @OA\Post(
     *     path="/followups",
     *     tags={"Followups"},
     *     summary="Create a new follow-up",
     *     description="Schedule a new follow-up message for a lead. The message will be sent automatically when scheduled_at time arrives.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "message", "scheduled_at"},
     *             @OA\Property(property="lead_id", type="integer", example=1),
     *             @OA\Property(property="message", type="string", example="Hi! Just following up on our conversation."),
     *             @OA\Property(property="scheduled_at", type="string", format="date-time", example="2026-05-16 10:00:00")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Follow-up created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Follow-up created successfully"),
     *             @OA\Property(property="data", type="object")
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
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lead_id' => 'required|integer',
                'message' => 'required|string|max:4096',
                'scheduled_at' => 'required|date|after:now',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Multi-tenant: verify lead ownership
            $lead = Lead::where('id', $request->lead_id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$lead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            DB::beginTransaction();

            $followup = Followup::create([
                'lead_id' => $lead->id,
                'message' => $request->message,
                'scheduled_at' => $request->scheduled_at,
                'status' => 'pending',
            ]);

            DB::commit();

            Log::info('Follow-up created.', [
                'followup_id' => $followup->id,
                'lead_id' => $lead->id,
                'scheduled_at' => $request->scheduled_at,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Follow-up created successfully',
                'data' => $followup->load('lead:id,name,phone')
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error creating follow-up: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error creating follow-up',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update follow-up
     *
     * @OA\Put(
     *     path="/followups/{id}",
     *     tags={"Followups"},
     *     summary="Update a follow-up",
     *     description="Update an existing pending follow-up. Only pending follow-ups can be updated.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Follow-up ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="scheduled_at", type="string", format="date-time")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Follow-up updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Follow-up updated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Cannot update non-pending follow-up",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Follow-up not found",
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
    public function update(Request $request, $id)
    {
        try {
            $userId = auth()->id();

            // Multi-tenant: find follow-up that belongs to this user's leads
            $followup = Followup::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->where('id', $id)->first();

            if (!$followup) {
                return response()->json([
                    'status' => false,
                    'message' => 'Follow-up not found'
                ], 404);
            }

            // Only pending follow-ups can be updated
            if ($followup->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only pending follow-ups can be updated'
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'message' => 'sometimes|string|max:4096',
                'scheduled_at' => 'sometimes|date|after:now',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $followup->update($request->only(['message', 'scheduled_at']));

            DB::commit();

            Log::info('Follow-up updated.', [
                'followup_id' => $followup->id,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Follow-up updated successfully',
                'data' => $followup->fresh()->load('lead:id,name,phone')
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error updating follow-up',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete follow-up
     *
     * @OA\Delete(
     *     path="/followups/{id}",
     *     tags={"Followups"},
     *     summary="Delete a follow-up",
     *     description="Delete a follow-up. Only pending follow-ups can be deleted.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Follow-up ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Follow-up deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Follow-up deleted successfully")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Cannot delete non-pending follow-up",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Follow-up not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function destroy($id)
    {
        try {
            $userId = auth()->id();

            $followup = Followup::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->where('id', $id)->first();

            if (!$followup) {
                return response()->json([
                    'status' => false,
                    'message' => 'Follow-up not found'
                ], 404);
            }

            if ($followup->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only pending follow-ups can be deleted'
                ], 400);
            }

            DB::beginTransaction();

            $followup->delete();

            DB::commit();

            Log::info('Follow-up deleted.', [
                'followup_id' => $id,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Follow-up deleted successfully'
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error deleting follow-up',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Manually trigger a follow-up
     *
     * @OA\Post(
     *     path="/followups/{id}/send",
     *     tags={"Followups"},
     *     summary="Manually trigger a follow-up",
     *     description="Immediately dispatch a pending follow-up to the queue for sending, regardless of scheduled_at time.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Follow-up ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Follow-up dispatched for sending",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Follow-up dispatched for sending")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Follow-up is not in pending status",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Follow-up not found",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function sendNow($id)
    {
        try {
            $userId = auth()->id();

            $followup = Followup::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->where('id', $id)->first();

            if (!$followup) {
                return response()->json([
                    'status' => false,
                    'message' => 'Follow-up not found'
                ], 404);
            }

            if ($followup->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only pending follow-ups can be sent'
                ], 400);
            }

            // Dispatch to queue immediately
            SendFollowupJob::dispatch($followup);

            Log::info('Follow-up manually dispatched for sending.', [
                'followup_id' => $followup->id,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Follow-up dispatched for sending'
            ], 200);

        } catch (Exception $e) {
            Log::error('Error dispatching follow-up: ' . $e->getMessage(), [
                'followup_id' => $id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error dispatching follow-up',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Change follow-up status
     *
     * @OA\Patch(
     *     path="/followups/{id}/status",
     *     tags={"Followups"},
     *     summary="Change follow-up status",
     *     description="Manually change the status of a follow-up (e.g., reset a failed follow-up to pending for retry).",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Follow-up ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"status"},
     *             @OA\Property(property="status", type="string", enum={"pending", "sent", "failed"}, example="pending")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Follow-up status updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Follow-up status updated successfully"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Follow-up not found",
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
    public function changeStatus(Request $request, $id)
    {
        try {
            $userId = auth()->id();

            $followup = Followup::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->where('id', $id)->first();

            if (!$followup) {
                return response()->json([
                    'status' => false,
                    'message' => 'Follow-up not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:pending,sent,failed',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $followup->update(['status' => $request->status]);

            DB::commit();

            Log::info('Follow-up status changed.', [
                'followup_id' => $followup->id,
                'new_status' => $request->status,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Follow-up status updated successfully',
                'data' => $followup->fresh()->load('lead:id,name,phone')
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error updating follow-up status',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
