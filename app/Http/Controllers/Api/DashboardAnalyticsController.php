<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class DashboardAnalyticsController extends Controller
{
    protected DashboardAnalyticsService $analyticsService;

    public function __construct(DashboardAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Initialize the analytics service with date filters from request.
     */
    private function initService(Request $request): DashboardAnalyticsService
    {
        return $this->analyticsService->forUser(
            auth()->id(),
            $request->get('period'),
            $request->get('start_date'),
            $request->get('end_date')
        );
    }

    /**
     * Validate optional date filter parameters.
     */
    private function validateDateFilters(Request $request)
    {
        return Validator::make($request->all(), [
            'period'     => 'sometimes|string|in:today,yesterday,last_7_days,last_30_days,this_month,last_month,this_year',
            'start_date' => 'sometimes|date|required_with:end_date',
            'end_date'   => 'sometimes|date|required_with:start_date|after_or_equal:start_date',
        ]);
    }

    /**
     * Dashboard overview
     *
     * @OA\Get(
     *     path="/dashboard/overview",
     *     tags={"Dashboard Analytics"},
     *     summary="Dashboard overview statistics",
     *     description="Get high-level KPIs: total leads, orders, revenue, messages, followups, conversion rate. All data scoped to the authenticated user.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, description="Preset date filter", @OA\Schema(type="string", enum={"today","yesterday","last_7_days","last_30_days","this_month","last_month","this_year"})),
     *     @OA\Parameter(name="start_date", in="query", required=false, description="Custom start date (YYYY-MM-DD)", @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, description="Custom end date (YYYY-MM-DD)", @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Overview statistics fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string", example="Overview statistics fetched successfully"),
     *             @OA\Property(
     *                 property="data",
     *                 type="object",
     *                 @OA\Property(property="total_leads", type="integer", example=150),
     *                 @OA\Property(property="total_orders", type="integer", example=42),
     *                 @OA\Property(property="total_revenue", type="number", format="float", example=12500.50),
     *                 @OA\Property(property="total_messages", type="integer", example=890),
     *                 @OA\Property(property="total_followups", type="integer", example=65),
     *                 @OA\Property(property="pending_followups", type="integer", example=12),
     *                 @OA\Property(property="delivered_orders", type="integer", example=35),
     *                 @OA\Property(property="conversion_rate", type="number", format="float", example=23.33)
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error", @OA\JsonContent(ref="#/components/schemas/ValidationErrorResponse")),
     *     @OA\Response(response=500, description="Server error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function overview(Request $request)
    {
        try {
            $validator = $this->validateDateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $service = $this->initService($request);

            return response()->json([
                'status'  => true,
                'message' => 'Overview statistics fetched successfully',
                'data'    => $service->getOverview()
            ], 200);

        } catch (Exception $e) {
            Log::error('Dashboard overview error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error fetching overview statistics',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lead analytics
     *
     * @OA\Get(
     *     path="/dashboard/leads",
     *     tags={"Dashboard Analytics"},
     *     summary="Lead analytics",
     *     description="Get lead breakdown by status, source, daily growth, and monthly growth. Chart-ready labels/datasets format.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"today","yesterday","last_7_days","last_30_days","this_month","last_month","this_year"})),
     *     @OA\Parameter(name="start_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Lead analytics fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="by_status", type="array", @OA\Items(type="object", @OA\Property(property="status", type="string"), @OA\Property(property="count", type="integer"))),
     *                 @OA\Property(property="by_source", type="array", @OA\Items(type="object", @OA\Property(property="source", type="string"), @OA\Property(property="count", type="integer"))),
     *                 @OA\Property(property="daily_growth", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="array", @OA\Items(type="integer")),
     *                     @OA\Property(property="total", type="integer")
     *                 ),
     *                 @OA\Property(property="monthly_growth", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="array", @OA\Items(type="integer")),
     *                     @OA\Property(property="total", type="integer")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function leads(Request $request)
    {
        try {
            $validator = $this->validateDateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $service = $this->initService($request);

            return response()->json([
                'status'  => true,
                'message' => 'Lead analytics fetched successfully',
                'data'    => $service->getLeadAnalytics()
            ], 200);

        } catch (Exception $e) {
            Log::error('Lead analytics error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error fetching lead analytics',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Revenue analytics
     *
     * @OA\Get(
     *     path="/dashboard/revenue",
     *     tags={"Dashboard Analytics"},
     *     summary="Revenue and order analytics",
     *     description="Get orders by status, daily/monthly revenue, top 10 customers, and average order value.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"today","yesterday","last_7_days","last_30_days","this_month","last_month","this_year"})),
     *     @OA\Parameter(name="start_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Revenue analytics fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="by_status", type="array", @OA\Items(type="object")),
     *                 @OA\Property(property="average_order_value", type="number", format="float"),
     *                 @OA\Property(property="daily_revenue", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="object"),
     *                     @OA\Property(property="total_revenue", type="number"),
     *                     @OA\Property(property="total_orders", type="integer")
     *                 ),
     *                 @OA\Property(property="monthly_revenue", type="object"),
     *                 @OA\Property(property="top_customers", type="array", @OA\Items(type="object"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function revenue(Request $request)
    {
        try {
            $validator = $this->validateDateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $service = $this->initService($request);

            return response()->json([
                'status'  => true,
                'message' => 'Revenue analytics fetched successfully',
                'data'    => $service->getRevenueAnalytics()
            ], 200);

        } catch (Exception $e) {
            Log::error('Revenue analytics error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error fetching revenue analytics',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Message analytics
     *
     * @OA\Get(
     *     path="/dashboard/messages",
     *     tags={"Dashboard Analytics"},
     *     summary="Message analytics",
     *     description="Get incoming/outgoing message counts and messages-per-day breakdown.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"today","yesterday","last_7_days","last_30_days","this_month","last_month","this_year"})),
     *     @OA\Parameter(name="start_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Message analytics fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="incoming_count", type="integer", example=450),
     *                 @OA\Property(property="outgoing_count", type="integer", example=340),
     *                 @OA\Property(property="total_count", type="integer", example=790),
     *                 @OA\Property(property="messages_per_day", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="object",
     *                         @OA\Property(property="incoming", type="array", @OA\Items(type="integer")),
     *                         @OA\Property(property="outgoing", type="array", @OA\Items(type="integer")),
     *                         @OA\Property(property="total", type="array", @OA\Items(type="integer"))
     *                     )
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function messages(Request $request)
    {
        try {
            $validator = $this->validateDateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $service = $this->initService($request);

            return response()->json([
                'status'  => true,
                'message' => 'Message analytics fetched successfully',
                'data'    => $service->getMessageAnalytics()
            ], 200);

        } catch (Exception $e) {
            Log::error('Message analytics error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error fetching message analytics',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Followup analytics
     *
     * @OA\Get(
     *     path="/dashboard/followups",
     *     tags={"Dashboard Analytics"},
     *     summary="Follow-up analytics",
     *     description="Get sent/pending/failed followup counts and per-day breakdown.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"today","yesterday","last_7_days","last_30_days","this_month","last_month","this_year"})),
     *     @OA\Parameter(name="start_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Followup analytics fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="sent", type="integer", example=45),
     *                 @OA\Property(property="pending", type="integer", example=12),
     *                 @OA\Property(property="failed", type="integer", example=3),
     *                 @OA\Property(property="total", type="integer", example=60),
     *                 @OA\Property(property="by_day", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="object")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function followups(Request $request)
    {
        try {
            $validator = $this->validateDateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $service = $this->initService($request);

            return response()->json([
                'status'  => true,
                'message' => 'Followup analytics fetched successfully',
                'data'    => $service->getFollowupAnalytics()
            ], 200);

        } catch (Exception $e) {
            Log::error('Followup analytics error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error fetching followup analytics',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Charts analytics
     *
     * @OA\Get(
     *     path="/dashboard/charts",
     *     tags={"Dashboard Analytics"},
     *     summary="Charts analytics (consolidated)",
     *     description="Get all chart-ready data in a single call: lead funnel, revenue trend (30d), message activity (30d), lead sources pie, order status distribution.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="period", in="query", required=false, @OA\Schema(type="string", enum={"today","yesterday","last_7_days","last_30_days","this_month","last_month","this_year"})),
     *     @OA\Parameter(name="start_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Parameter(name="end_date", in="query", required=false, @OA\Schema(type="string", format="date")),
     *     @OA\Response(
     *         response=200,
     *         description="Charts data fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="lead_funnel", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="array", @OA\Items(type="integer"))
     *                 ),
     *                 @OA\Property(property="revenue_trend", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="array", @OA\Items(type="number"))
     *                 ),
     *                 @OA\Property(property="message_activity", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="object")
     *                 ),
     *                 @OA\Property(property="lead_sources", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="array", @OA\Items(type="integer"))
     *                 ),
     *                 @OA\Property(property="order_status", type="object",
     *                     @OA\Property(property="labels", type="array", @OA\Items(type="string")),
     *                     @OA\Property(property="datasets", type="array", @OA\Items(type="integer"))
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function charts(Request $request)
    {
        try {
            $validator = $this->validateDateFilters($request);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $service = $this->initService($request);

            return response()->json([
                'status'  => true,
                'message' => 'Charts data fetched successfully',
                'data'    => $service->getChartsData()
            ], 200);

        } catch (Exception $e) {
            Log::error('Charts analytics error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error fetching charts data',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
