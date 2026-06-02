<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Exception;

class LeadController extends Controller
{
    /**
     * Get all leads
     *
     * @OA\Get(
     *     path="/leads",
     *     tags={"Leads"},
     *     summary="Get all leads",
     *     description="Retrieve all leads with optional filtering, searching and pagination",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by lead status",
     *         required=false,
     *         @OA\Schema(type="string", enum={"new", "contacted", "qualified", "negotiation", "won", "lost"})
     *     ),
     *     @OA\Parameter(
     *         name="source",
     *         in="query",
     *         description="Filter by lead source",
     *         required=false,
     *         @OA\Schema(type="string", example="website")
     *     ),
     *     @OA\Parameter(
     *         name="assigned_to",
     *         in="query",
     *         description="Filter by assigned user ID",
     *         required=false,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Parameter(
     *         name="search",
     *         in="query",
     *         description="Search by lead name or phone",
     *         required=false,
     *         @OA\Schema(type="string")
     *     ),
     *     @OA\Parameter(
     *         name="per_page",
     *         in="query",
     *         description="Records per page (default: 10)",
     *         required=false,
     *         @OA\Schema(type="integer", example=10)
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Leads fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Leads fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(
     *                     property="data",
     *                     type="array",
     *                     @OA\Items(ref="#/components/schemas/Lead")
     *                 ),
     *                 @OA\Property(property="current_page", type="integer"),
     *                 @OA\Property(property="per_page", type="integer"),
     *                 @OA\Property(property="total", type="integer")
     *             )
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
            // Multi-tenant: only fetch leads owned by authenticated user
            $query = Lead::where('user_id', auth()->id());

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by source
            if ($request->has('source')) {
                $query->where('source', $request->source);
            }

            // Filter by assigned_to
            if ($request->has('assigned_to')) {
                $query->where('assigned_to', $request->assigned_to);
            }

            // Search by name or phone
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            // Pagination
            $leads = $query->paginate($request->get('per_page', 10));

            return response()->json([
                'status' => true,
                'message' => 'Leads fetched successfully',
                'data' => $leads
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching leads',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create a new lead
     *
     * @OA\Post(
     *     path="/leads",
     *     tags={"Leads"},
     *     summary="Create a new lead",
     *     description="Create a new lead in the system",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name","phone","source","status"},
     *             @OA\Property(property="name", type="string", example="Ahmed Khan"),
     *             @OA\Property(property="phone", type="string", example="+92300123456"),
     *             @OA\Property(property="source", type="string", example="website"),
     *             @OA\Property(property="status", type="string", enum={"new", "contacted", "qualified", "negotiation", "won", "lost"}),
     *             @OA\Property(property="assigned_to", type="integer", nullable=true, example=1),
     *             @OA\Property(property="notes", type="string", nullable=true, example="Important lead")
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Lead created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead created successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Lead")
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
    public function store(Request $request)
    {
        try {
            $userId = auth()->id();

            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:100',
                'phone' => 'required|string|max:20',
                'source' => 'required|string|max:50',
                'status' => 'required|in:new,contacted,qualified,negotiation,won,lost',
                'assigned_to' => 'nullable|exists:users,id',
                'notes' => 'nullable|string'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            // Multi-tenant: phone must be unique per user
            $existingLead = Lead::where('user_id', $userId)
                ->where('phone', $request->phone)
                ->first();

            if ($existingLead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => ['phone' => ['A lead with this phone number already exists.']]
                ], 422);
            }

            DB::beginTransaction();

            $lead = Lead::create([
                'user_id' => $userId,
                'name' => $request->name,
                'phone' => $request->phone,
                'source' => $request->source,
                'status' => $request->status,
                'assigned_to' => $request->assigned_to,
                'notes' => $request->notes
            ]);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Lead created successfully',
                'data' => $lead
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error creating lead',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a specific lead
     *
     * @OA\Get(
     *     path="/leads/{id}",
     *     tags={"Leads"},
     *     summary="Get a specific lead",
     *     description="Retrieve a specific lead with all relationships",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Lead ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead fetched successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Lead")
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
    public function show($id)
    {
        try {
            // Multi-tenant: only fetch leads owned by authenticated user
            $lead = Lead::with(['messages', 'orders', 'followups', 'assignedUser'])
                ->where('user_id', auth()->id())
                ->where('id', $id)
                ->first();

            if (!$lead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Lead fetched successfully',
                'data' => $lead
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching lead',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a lead
     *
     * @OA\Put(
     *     path="/leads/{id}",
     *     tags={"Leads"},
     *     summary="Update a lead",
     *     description="Update an existing lead",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Lead ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="name", type="string", example="Ahmed Khan"),
     *             @OA\Property(property="phone", type="string", example="+92300123456"),
     *             @OA\Property(property="source", type="string", example="website"),
     *             @OA\Property(property="status", type="string", enum={"new", "contacted", "qualified", "negotiation", "won", "lost"}),
     *             @OA\Property(property="assigned_to", type="integer", nullable=true),
     *             @OA\Property(property="notes", type="string", nullable=true)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead updated successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead updated successfully"),
     *             @OA\Property(property="data", ref="#/components/schemas/Lead")
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
    public function update(Request $request, $id)
    {
        try {
            $userId = auth()->id();

            // Multi-tenant: only update leads owned by authenticated user
            $lead = Lead::where('id', $id)
                ->where('user_id', $userId)
                ->first();

            if (!$lead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            $validator = Validator::make($request->all(), [
                'name' => 'sometimes|string|max:100',
                'phone' => 'sometimes|string|max:20',
                'source' => 'sometimes|string|max:50',
                'status' => 'sometimes|in:new,contacted,qualified,negotiation,won,lost',
                'assigned_to' => 'nullable|exists:users,id',
                'notes' => 'nullable|string'
            ]);

            // Multi-tenant: phone must be unique per user (excluding current lead)
            if ($request->has('phone')) {
                $phoneDuplicate = Lead::where('user_id', $userId)
                    ->where('phone', $request->phone)
                    ->where('id', '!=', $id)
                    ->exists();

                if ($phoneDuplicate) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Validation error',
                        'errors' => ['phone' => ['A lead with this phone number already exists.']]
                    ], 422);
                }
            }

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            DB::beginTransaction();

            $lead->update($request->only([
                'name', 'phone', 'source', 'status', 'assigned_to', 'notes'
            ]));

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Lead updated successfully',
                'data' => $lead
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error updating lead',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a lead
     *
     * @OA\Delete(
     *     path="/leads/{id}",
     *     tags={"Leads"},
     *     summary="Delete a lead",
     *     description="Delete a lead from the system",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         description="Lead ID",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Lead deleted successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Lead deleted successfully")
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
    public function destroy($id)
    {
        try {
            // Multi-tenant: only delete leads owned by authenticated user
            $lead = Lead::where('id', $id)
                ->where('user_id', auth()->id())
                ->first();

            if (!$lead) {
                return response()->json([
                    'status' => false,
                    'message' => 'Lead not found'
                ], 404);
            }

            DB::beginTransaction();

            $lead->delete();

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Lead deleted successfully'
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error deleting lead',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get lead statistics
     *
     * @OA\Get(
     *     path="/leads-statistics",
     *     tags={"Leads"},
     *     summary="Get lead statistics",
     *     description="Get statistics about leads including counts by status and source",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Statistics fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Statistics fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="total_leads", type="integer", example=42),
     *                 @OA\Property(property="assigned_leads", type="integer", example=35),
     *                 @OA\Property(property="unassigned_leads", type="integer", example=7),
     *                 @OA\Property(
     *                     property="by_status",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="status", type="string"),
     *                         @OA\Property(property="count", type="integer")
     *                     )
     *                 ),
     *                 @OA\Property(
     *                     property="by_source",
     *                     type="array",
     *                     @OA\Items(
     *                         type="object",
     *                         @OA\Property(property="source", type="string"),
     *                         @OA\Property(property="count", type="integer")
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=500,
     *         description="Server error",
     *         @OA\JsonContent(ref="#/components/schemas/ErrorResponse")
     *     )
     * )
     */
    public function statistics()
    {
        try {
            // Multi-tenant: only count leads owned by authenticated user
            $userId = auth()->id();

            $stats = [
                'total_leads' => Lead::where('user_id', $userId)->count(),
                'by_status' => Lead::where('user_id', $userId)->groupBy('status')->selectRaw('status, count(*) as count')->get(),
                'by_source' => Lead::where('user_id', $userId)->groupBy('source')->selectRaw('source, count(*) as count')->get(),
                'assigned_leads' => Lead::where('user_id', $userId)->whereNotNull('assigned_to')->count(),
                'unassigned_leads' => Lead::where('user_id', $userId)->whereNull('assigned_to')->count()
            ];

            return response()->json([
                'status' => true,
                'message' => 'Statistics fetched successfully',
                'data' => $stats
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
