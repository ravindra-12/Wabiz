<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'price',
        'billing_cycle',
        'max_leads',
        'max_messages',
        'max_orders',
        'features',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'price'    => 'decimal:2',
            'features' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * All subscriptions using this plan.
     */
    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class, 'subscription_plan_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter only active plans.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Check if this is a free plan.
     */
    public function isFreePlan(): bool
    {
        return (float) $this->price === 0.00;
    }
}
