<?php

namespace App\Services;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardAnalyticsService
{
    protected int $userId;
    protected ?Carbon $startDate;
    protected ?Carbon $endDate;

    /**
     * Initialize the service for a specific tenant and date range.
     */
    public function forUser(int $userId, ?string $period = null, ?string $startDate = null, ?string $endDate = null): self
    {
        $this->userId = $userId;
        [$this->startDate, $this->endDate] = $this->resolveDateRange($period, $startDate, $endDate);
        return $this;
    }

    // ═══════════════════════════════════════════════════════════
    //  OVERVIEW
    // ═══════════════════════════════════════════════════════════

    /**
     * Dashboard overview — high-level KPIs.
     */
    public function getOverview(): array
    {
        $leadIds = $this->tenantLeadIds();

        $totalLeads       = $this->tenantLeadsQuery()->count();
        $totalOrders      = $this->tenantOrdersQuery($leadIds)->count();
        $totalRevenue     = $this->tenantOrdersQuery($leadIds)->where('status', 'delivered')->sum('total_price');
        $totalMessages    = $this->tenantMessagesQuery($leadIds)->count();
        $totalFollowups   = $this->tenantFollowupsQuery($leadIds)->count();
        $pendingFollowups = $this->tenantFollowupsQuery($leadIds)->where('status', 'pending')->count();
        $deliveredOrders  = $this->tenantOrdersQuery($leadIds)->where('status', 'delivered')->count();

        $convertedLeads = $this->tenantLeadsQuery()->where('status', 'converted')->count();
        $conversionRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100, 2) : 0;

        return [
            'total_leads'       => $totalLeads,
            'total_orders'      => $totalOrders,
            'total_revenue'     => round($totalRevenue, 2),
            'total_messages'    => $totalMessages,
            'total_followups'   => $totalFollowups,
            'pending_followups' => $pendingFollowups,
            'delivered_orders'  => $deliveredOrders,
            'conversion_rate'   => $conversionRate,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  LEAD ANALYTICS
    // ═══════════════════════════════════════════════════════════

    /**
     * Lead analytics — breakdown by status, source, daily & monthly growth.
     */
    public function getLeadAnalytics(): array
    {
        $byStatus = $this->tenantLeadsQuery()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        $bySource = $this->tenantLeadsQuery()
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->get();

        $dailyGrowth = $this->tenantLeadsQuery()
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $monthlyGrowth = $this->tenantLeadsQuery()
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('count(*) as count')
            )
            ->groupBy('year', 'month')
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get();

        return [
            'by_status' => $byStatus,
            'by_source' => $bySource,
            'daily_growth' => [
                'labels'   => $dailyGrowth->pluck('date'),
                'datasets' => $dailyGrowth->pluck('count'),
                'total'    => $dailyGrowth->sum('count'),
            ],
            'monthly_growth' => [
                'labels'   => $monthlyGrowth->map(fn($r) => "{$r->year}-" . str_pad($r->month, 2, '0', STR_PAD_LEFT)),
                'datasets' => $monthlyGrowth->pluck('count'),
                'total'    => $monthlyGrowth->sum('count'),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  REVENUE / ORDER ANALYTICS
    // ═══════════════════════════════════════════════════════════

    /**
     * Revenue analytics — orders by status, daily & monthly revenue, top customers, AOV.
     */
    public function getRevenueAnalytics(): array
    {
        $leadIds = $this->tenantLeadIds();

        $byStatus = $this->tenantOrdersQuery($leadIds)
            ->select('status', DB::raw('count(*) as count'), DB::raw('sum(total_price) as revenue'))
            ->groupBy('status')
            ->get();

        $dailyRevenue = $this->tenantOrdersQuery($leadIds)
            ->where('status', 'delivered')
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('count(*) as orders'),
                DB::raw('sum(total_price) as revenue')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        $monthlyRevenue = $this->tenantOrdersQuery($leadIds)
            ->where('status', 'delivered')
            ->select(
                DB::raw('YEAR(created_at) as year'),
                DB::raw('MONTH(created_at) as month'),
                DB::raw('count(*) as orders'),
                DB::raw('sum(total_price) as revenue')
            )
            ->groupBy('year', 'month')
            ->orderBy('year', 'asc')
            ->orderBy('month', 'asc')
            ->get();

        $topCustomers = Lead::where('user_id', $this->userId)
            ->whereHas('orders', function ($q) {
                $q->where('status', 'delivered');
                $this->applyDateFilter($q);
            })
            ->withCount(['orders as delivered_orders' => function ($q) {
                $q->where('status', 'delivered');
                $this->applyDateFilter($q);
            }])
            ->withSum(['orders as total_spent' => function ($q) {
                $q->where('status', 'delivered');
                $this->applyDateFilter($q);
            }], 'total_price')
            ->orderByDesc('total_spent')
            ->limit(10)
            ->get(['id', 'name', 'phone']);

        $deliveredCount = $this->tenantOrdersQuery($leadIds)->where('status', 'delivered')->count();
        $avgOrderValue = $deliveredCount > 0
            ? round($this->tenantOrdersQuery($leadIds)->where('status', 'delivered')->avg('total_price'), 2)
            : 0;

        return [
            'by_status'           => $byStatus,
            'average_order_value' => $avgOrderValue,
            'daily_revenue' => [
                'labels'   => $dailyRevenue->pluck('date'),
                'datasets' => [
                    'revenue' => $dailyRevenue->pluck('revenue'),
                    'orders'  => $dailyRevenue->pluck('orders'),
                ],
                'total_revenue' => round($dailyRevenue->sum('revenue'), 2),
                'total_orders'  => $dailyRevenue->sum('orders'),
            ],
            'monthly_revenue' => [
                'labels'   => $monthlyRevenue->map(fn($r) => "{$r->year}-" . str_pad($r->month, 2, '0', STR_PAD_LEFT)),
                'datasets' => [
                    'revenue' => $monthlyRevenue->pluck('revenue'),
                    'orders'  => $monthlyRevenue->pluck('orders'),
                ],
                'total_revenue' => round($monthlyRevenue->sum('revenue'), 2),
                'total_orders'  => $monthlyRevenue->sum('orders'),
            ],
            'top_customers' => $topCustomers,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  MESSAGE ANALYTICS
    // ═══════════════════════════════════════════════════════════

    /**
     * Message analytics — incoming/outgoing counts and per-day breakdown.
     */
    public function getMessageAnalytics(): array
    {
        $leadIds = $this->tenantLeadIds();

        $incoming = $this->tenantMessagesQuery($leadIds)->where('type', 'incoming')->count();
        $outgoing = $this->tenantMessagesQuery($leadIds)->where('type', 'outgoing')->count();

        $messagesPerDay = $this->tenantMessagesQuery($leadIds)
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN type = 'incoming' THEN 1 ELSE 0 END) as incoming"),
                DB::raw("SUM(CASE WHEN type = 'outgoing' THEN 1 ELSE 0 END) as outgoing"),
                DB::raw('count(*) as total')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return [
            'incoming_count' => $incoming,
            'outgoing_count' => $outgoing,
            'total_count'    => $incoming + $outgoing,
            'messages_per_day' => [
                'labels'   => $messagesPerDay->pluck('date'),
                'datasets' => [
                    'incoming' => $messagesPerDay->pluck('incoming'),
                    'outgoing' => $messagesPerDay->pluck('outgoing'),
                    'total'    => $messagesPerDay->pluck('total'),
                ],
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  FOLLOWUP ANALYTICS
    // ═══════════════════════════════════════════════════════════

    /**
     * Followup analytics — status counts and per-day breakdown.
     */
    public function getFollowupAnalytics(): array
    {
        $leadIds = $this->tenantLeadIds();

        $sent    = $this->tenantFollowupsQuery($leadIds)->where('status', 'sent')->count();
        $pending = $this->tenantFollowupsQuery($leadIds)->where('status', 'pending')->count();
        $failed  = $this->tenantFollowupsQuery($leadIds)->where('status', 'failed')->count();

        $byDay = $this->tenantFollowupsQuery($leadIds)
            ->select(
                DB::raw('DATE(scheduled_at) as date'),
                DB::raw("SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent"),
                DB::raw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending"),
                DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"),
                DB::raw('count(*) as total')
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        return [
            'sent'    => $sent,
            'pending' => $pending,
            'failed'  => $failed,
            'total'   => $sent + $pending + $failed,
            'by_day' => [
                'labels'   => $byDay->pluck('date'),
                'datasets' => [
                    'sent'    => $byDay->pluck('sent'),
                    'pending' => $byDay->pluck('pending'),
                    'failed'  => $byDay->pluck('failed'),
                ],
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  CHARTS DATA (consolidated for frontend)
    // ═══════════════════════════════════════════════════════════

    /**
     * Consolidated chart-ready data for the frontend dashboard.
     */
    public function getChartsData(): array
    {
        $leadIds = $this->tenantLeadIds();

        // Lead funnel
        $leadFunnel = $this->tenantLeadsQuery()
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        // Revenue trend (last 30 days — ignores date filter intentionally)
        $revenueTrend = Order::whereIn('lead_id', Lead::where('user_id', $this->userId)->pluck('id'))
            ->where('status', 'delivered')
            ->where('created_at', '>=', now()->subDays(30))
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('sum(total_price) as revenue'))
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // Message activity (last 30 days)
        $messageActivity = Message::whereIn('lead_id', Lead::where('user_id', $this->userId)->pluck('id'))
            ->where('created_at', '>=', now()->subDays(30))
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw("SUM(CASE WHEN type = 'incoming' THEN 1 ELSE 0 END) as incoming"),
                DB::raw("SUM(CASE WHEN type = 'outgoing' THEN 1 ELSE 0 END) as outgoing")
            )
            ->groupBy('date')
            ->orderBy('date', 'asc')
            ->get();

        // Lead sources pie
        $leadSources = $this->tenantLeadsQuery()
            ->select('source', DB::raw('count(*) as count'))
            ->groupBy('source')
            ->get();

        // Order status distribution
        $orderStatus = $this->tenantOrdersQuery($leadIds)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        return [
            'lead_funnel' => [
                'labels'   => $leadFunnel->pluck('status'),
                'datasets' => $leadFunnel->pluck('count'),
            ],
            'revenue_trend' => [
                'labels'   => $revenueTrend->pluck('date'),
                'datasets' => $revenueTrend->pluck('revenue'),
            ],
            'message_activity' => [
                'labels'   => $messageActivity->pluck('date'),
                'datasets' => [
                    'incoming' => $messageActivity->pluck('incoming'),
                    'outgoing' => $messageActivity->pluck('outgoing'),
                ],
            ],
            'lead_sources' => [
                'labels'   => $leadSources->pluck('source'),
                'datasets' => $leadSources->pluck('count'),
            ],
            'order_status' => [
                'labels'   => $orderStatus->pluck('status'),
                'datasets' => $orderStatus->pluck('count'),
            ],
        ];
    }

    // ═══════════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ═══════════════════════════════════════════════════════════

    /**
     * Get tenant lead IDs (for whereIn on child tables).
     */
    private function tenantLeadIds()
    {
        return Lead::where('user_id', $this->userId)->pluck('id');
    }

    /**
     * Base tenant-scoped leads query with optional date filter.
     */
    private function tenantLeadsQuery()
    {
        $q = Lead::where('user_id', $this->userId);
        $this->applyDateFilter($q);
        return $q;
    }

    /**
     * Base tenant-scoped orders query with optional date filter.
     */
    private function tenantOrdersQuery($leadIds)
    {
        $q = Order::whereIn('lead_id', $leadIds);
        $this->applyDateFilter($q);
        return $q;
    }

    /**
     * Base tenant-scoped messages query with optional date filter.
     */
    private function tenantMessagesQuery($leadIds)
    {
        $q = Message::whereIn('lead_id', $leadIds);
        $this->applyDateFilter($q);
        return $q;
    }

    /**
     * Base tenant-scoped followups query with optional date filter.
     */
    private function tenantFollowupsQuery($leadIds)
    {
        $q = Followup::whereIn('lead_id', $leadIds);
        $this->applyDateFilter($q, 'scheduled_at');
        return $q;
    }

    /**
     * Apply date range filter to a query builder.
     */
    private function applyDateFilter($query, string $column = 'created_at'): void
    {
        if ($this->startDate) {
            $query->where($column, '>=', $this->startDate);
        }
        if ($this->endDate) {
            $query->where($column, '<=', $this->endDate);
        }
    }

    /**
     * Resolve date range from a preset period or custom start/end.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    private function resolveDateRange(?string $period, ?string $startDate, ?string $endDate): array
    {
        if ($startDate && $endDate) {
            return [
                Carbon::parse($startDate)->startOfDay(),
                Carbon::parse($endDate)->endOfDay(),
            ];
        }

        return match ($period) {
            'today'        => [now()->startOfDay(), now()->endOfDay()],
            'yesterday'    => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            'last_7_days'  => [now()->subDays(7)->startOfDay(), now()->endOfDay()],
            'last_30_days' => [now()->subDays(30)->startOfDay(), now()->endOfDay()],
            'this_month'   => [now()->startOfMonth(), now()->endOfDay()],
            'last_month'   => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'this_year'    => [now()->startOfYear(), now()->endOfDay()],
            default        => [null, null], // All time
        };
    }
}
