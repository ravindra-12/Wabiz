<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\OrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class OrderController extends Controller
{
    protected OrderService $orderService;

    public function __construct(OrderService $orderService)
    {
        $this->orderService = $orderService;
    }

    /**
     * Get all orders
     *
     * @OA\Get(
     *     path="/orders",
     *     tags={"Orders"},
     *     summary="Get all orders",
     *     description="Retrieve all orders for leads owned by the authenticated user with optional filtering, searching and pagination",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string", enum={"pending","confirmed","packed","shipped","delivered","cancelled"})),
     *     @OA\Parameter(name="lead_id", in="query", required=false, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="search", in="query", description="Search by lead name, phone or order ID", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", example=15)),
     *     @OA\Response(response=200, description="Orders fetched successfully",
     *         @OA\JsonContent(@OA\Property(property="status", type="boolean", example=true), @OA\Property(property="message", type="string"), @OA\Property(property="data", type="object"))
     *     ),
     *     @OA\Response(response=500, description="Server error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function index(Request $request)
    {
        try {
            $userId = auth()->id();

            $query = Order::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->with(['lead:id,name,phone', 'items']);

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            // Filter by lead
            if ($request->has('lead_id')) {
                $leadOwned = Lead::where('id', $request->lead_id)
                    ->where('user_id', $userId)
                    ->exists();

                if (!$leadOwned) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Lead not found'
                    ], 404);
                }

                $query->where('lead_id', $request->lead_id);
            }

            // Search by lead name, phone, or order ID
            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('id', $search)
                      ->orWhereHas('lead', function ($lq) use ($search) {
                          $lq->where('name', 'like', "%{$search}%")
                             ->orWhere('phone', 'like', "%{$search}%");
                      });
                });
            }

            $orders = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => true,
                'message' => 'Orders fetched successfully',
                'data' => $orders
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get single order
     *
     * @OA\Get(
     *     path="/orders/{id}",
     *     tags={"Orders"},
     *     summary="Get a single order",
     *     description="Retrieve a specific order with all items and lead details",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Order fetched successfully",
     *         @OA\JsonContent(@OA\Property(property="status", type="boolean", example=true), @OA\Property(property="message", type="string"), @OA\Property(property="data", type="object"))
     *     ),
     *     @OA\Response(response=404, description="Order not found", @OA\JsonContent(ref="#/components/schemas/ErrorResponse")),
     *     @OA\Response(response=500, description="Server error", @OA\JsonContent(ref="#/components/schemas/ErrorResponse"))
     * )
     */
    public function show($id)
    {
        try {
            $userId = auth()->id();

            $order = Order::with(['lead:id,name,phone,status', 'items'])
                ->whereHas('lead', function ($q) use ($userId) {
                    $q->where('user_id', $userId);
                })
                ->where('id', $id)
                ->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            return response()->json([
                'status' => true,
                'message' => 'Order fetched successfully',
                'data' => $order
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get orders by lead
     *
     * @OA\Get(
     *     path="/orders/lead/{lead_id}",
     *     tags={"Orders"},
     *     summary="Get orders by lead",
     *     description="Retrieve all orders for a specific lead",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="lead_id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Parameter(name="status", in="query", required=false, @OA\Schema(type="string")),
     *     @OA\Parameter(name="per_page", in="query", required=false, @OA\Schema(type="integer", example=15)),
     *     @OA\Response(response=200, description="Orders fetched successfully"),
     *     @OA\Response(response=404, description="Lead not found"),
     *     @OA\Response(response=500, description="Server error")
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

            $query = Order::where('lead_id', $lead_id)
                ->with('items')
                ->orderBy('created_at', 'desc');

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            $orders = $query->paginate($request->get('per_page', 15));

            return response()->json([
                'status' => true,
                'message' => 'Orders fetched successfully',
                'data' => [
                    'lead' => [
                        'id' => $lead->id,
                        'name' => $lead->name,
                        'phone' => $lead->phone,
                    ],
                    'orders' => $orders,
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching orders',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Create order
     *
     * @OA\Post(
     *     path="/orders",
     *     tags={"Orders"},
     *     summary="Create a new order",
     *     description="Create a new order with multiple items. Total price is auto-calculated. Sends WhatsApp notification to the lead.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"lead_id", "items"},
     *             @OA\Property(property="lead_id", type="integer", example=1),
     *             @OA\Property(property="items", type="array",
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="product_name", type="string", example="Widget Pro"),
     *                     @OA\Property(property="quantity", type="integer", example=2),
     *                     @OA\Property(property="price", type="number", format="float", example=29.99)
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=201, description="Order created successfully",
     *         @OA\JsonContent(@OA\Property(property="status", type="boolean", example=true), @OA\Property(property="message", type="string"), @OA\Property(property="data", type="object"))
     *     ),
     *     @OA\Response(response=404, description="Lead not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'lead_id' => 'required|integer',
                'items' => 'required|array|min:1',
                'items.*.product_name' => 'required|string|max:255',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.price' => 'required|numeric|min:0.01',
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

            // Calculate total price
            $totalPrice = collect($request->items)->sum(function ($item) {
                return $item['quantity'] * $item['price'];
            });

            DB::beginTransaction();

            $order = Order::create([
                'lead_id' => $lead->id,
                'total_price' => $totalPrice,
                'status' => 'pending',
            ]);

            // Create order items
            foreach ($request->items as $item) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_name' => $item['product_name'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'],
                ]);
            }

            DB::commit();

            // Load relationships for response
            $order->load('items', 'lead:id,name,phone');

            Log::info('Order created.', [
                'order_id' => $order->id,
                'lead_id' => $lead->id,
                'total_price' => $totalPrice,
                'user_id' => auth()->id(),
            ]);

            // Send WhatsApp notification (non-blocking)
            $this->orderService->sendOrderNotification($order, 'created');

            return response()->json([
                'status' => true,
                'message' => 'Order created successfully',
                'data' => $order
            ], 201);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error creating order: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error creating order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update order
     *
     * @OA\Put(
     *     path="/orders/{id}",
     *     tags={"Orders"},
     *     summary="Update an order",
     *     description="Update order status and/or items. Sends WhatsApp notification on status change to shipped or delivered.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\RequestBody(required=false,
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"pending","confirmed","packed","shipped","delivered","cancelled"}),
     *             @OA\Property(property="items", type="array",
     *                 @OA\Items(type="object",
     *                     @OA\Property(property="product_name", type="string"),
     *                     @OA\Property(property="quantity", type="integer"),
     *                     @OA\Property(property="price", type="number", format="float")
     *                 )
     *             )
     *         )
     *     ),
     *     @OA\Response(response=200, description="Order updated successfully"),
     *     @OA\Response(response=400, description="Cannot update cancelled/delivered order"),
     *     @OA\Response(response=404, description="Order not found"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function update(Request $request, $id)
    {
        try {
            $userId = auth()->id();

            $order = Order::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->where('id', $id)->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            // Prevent updating cancelled or delivered orders
            if (in_array($order->status, ['cancelled', 'delivered'])) {
                return response()->json([
                    'status' => false,
                    'message' => "Cannot update a {$order->status} order"
                ], 400);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'sometimes|in:pending,confirmed,packed,shipped,delivered,cancelled',
                'items' => 'sometimes|array|min:1',
                'items.*.product_name' => 'required_with:items|string|max:255',
                'items.*.quantity' => 'required_with:items|integer|min:1',
                'items.*.price' => 'required_with:items|numeric|min:0.01',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $oldStatus = $order->status;

            DB::beginTransaction();

            // Update items if provided (replace all)
            if ($request->has('items')) {
                // Delete existing items and recreate
                $order->items()->delete();

                $totalPrice = 0;
                foreach ($request->items as $item) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'price' => $item['price'],
                    ]);
                    $totalPrice += $item['quantity'] * $item['price'];
                }

                $order->total_price = $totalPrice;
            }

            // Update status if provided
            if ($request->has('status')) {
                $order->status = $request->status;
            }

            $order->save();

            DB::commit();

            $order->load('items', 'lead:id,name,phone');

            Log::info('Order updated.', [
                'order_id' => $order->id,
                'user_id' => $userId,
                'old_status' => $oldStatus,
                'new_status' => $order->status,
            ]);

            // Send WhatsApp notification on specific status changes
            $newStatus = $order->status;
            if ($oldStatus !== $newStatus && in_array($newStatus, ['shipped', 'delivered'])) {
                $this->orderService->sendOrderNotification($order, $newStatus);
            }

            return response()->json([
                'status' => true,
                'message' => 'Order updated successfully',
                'data' => $order
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error updating order: ' . $e->getMessage(), [
                'order_id' => $id,
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error updating order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete order
     *
     * @OA\Delete(
     *     path="/orders/{id}",
     *     tags={"Orders"},
     *     summary="Delete an order",
     *     description="Delete an order. Only pending orders can be deleted.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Order deleted successfully"),
     *     @OA\Response(response=400, description="Cannot delete non-pending order"),
     *     @OA\Response(response=404, description="Order not found"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function destroy($id)
    {
        try {
            $userId = auth()->id();

            $order = Order::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })->where('id', $id)->first();

            if (!$order) {
                return response()->json([
                    'status' => false,
                    'message' => 'Order not found'
                ], 404);
            }

            if ($order->status !== 'pending') {
                return response()->json([
                    'status' => false,
                    'message' => 'Only pending orders can be deleted'
                ], 400);
            }

            DB::beginTransaction();

            $order->items()->delete();
            $order->delete();

            DB::commit();

            Log::info('Order deleted.', [
                'order_id' => $id,
                'user_id' => $userId,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Order deleted successfully'
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Error deleting order',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Order statistics
     *
     * @OA\Get(
     *     path="/orders-statistics",
     *     tags={"Orders"},
     *     summary="Get order statistics dashboard",
     *     description="Revenue stats, orders by status, monthly sales, and top customers for the authenticated user",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(response=200, description="Statistics fetched successfully",
     *         @OA\JsonContent(@OA\Property(property="status", type="boolean", example=true), @OA\Property(property="message", type="string"), @OA\Property(property="data", type="object"))
     *     ),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function statistics()
    {
        try {
            $userId = auth()->id();

            // Base query: orders belonging to this tenant's leads
            $baseQuery = Order::whereHas('lead', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            });

            // Total revenue (only delivered orders)
            $totalRevenue = (clone $baseQuery)
                ->where('status', 'delivered')
                ->sum('total_price');

            // Total orders
            $totalOrders = (clone $baseQuery)->count();

            // Orders by status
            $byStatus = (clone $baseQuery)
                ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_price) as revenue'))
                ->groupBy('status')
                ->get();

            // Monthly sales (last 12 months)
            $monthlySales = (clone $baseQuery)
                ->where('status', 'delivered')
                ->where('created_at', '>=', now()->subMonths(12))
                ->select(
                    DB::raw('YEAR(created_at) as year'),
                    DB::raw('MONTH(created_at) as month'),
                    DB::raw('count(*) as orders'),
                    DB::raw('sum(total_price) as revenue')
                )
                ->groupBy('year', 'month')
                ->orderBy('year', 'desc')
                ->orderBy('month', 'desc')
                ->get();

            // Top 10 customers by revenue
            $topCustomers = Lead::where('user_id', $userId)
                ->whereHas('orders', function ($q) {
                    $q->where('status', 'delivered');
                })
                ->withCount(['orders as delivered_orders' => function ($q) {
                    $q->where('status', 'delivered');
                }])
                ->withSum(['orders as total_spent' => function ($q) {
                    $q->where('status', 'delivered');
                }], 'total_price')
                ->orderByDesc('total_spent')
                ->limit(10)
                ->get(['id', 'name', 'phone']);

            // Average order value
            $avgOrderValue = $totalOrders > 0
                ? round((clone $baseQuery)->where('status', 'delivered')->avg('total_price'), 2)
                : 0;

            return response()->json([
                'status' => true,
                'message' => 'Statistics fetched successfully',
                'data' => [
                    'total_revenue' => round($totalRevenue, 2),
                    'total_orders' => $totalOrders,
                    'avg_order_value' => $avgOrderValue,
                    'by_status' => $byStatus,
                    'monthly_sales' => $monthlySales,
                    'top_customers' => $topCustomers,
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Error fetching order statistics: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Error fetching statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
