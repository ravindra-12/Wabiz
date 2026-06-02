<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UserSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_plan_id',
        'payment_gateway',
        'payment_id',
        'amount',
        'start_date',
        'expiry_date',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'amount'      => 'decimal:2',
            'start_date'  => 'datetime',
            'expiry_date' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * The user (tenant) who owns this subscription.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The plan associated with this subscription.
     */
    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    /**
     * Payment transactions linked to this subscription.
     */
    public function transactions()
    {
        return $this->hasMany(PaymentTransaction::class, 'subscription_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter only active, non-expired subscriptions.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                     ->where('expiry_date', '>', Carbon::now());
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Check if this subscription is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->expiry_date
            && $this->expiry_date->isFuture();
    }

    /**
     * Check if this subscription has expired.
     */
    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }

    /**
     * Get remaining days on subscription.
     */
    public function daysRemaining(): int
    {
        if (!$this->expiry_date || $this->expiry_date->isPast()) {
            return 0;
        }

        return (int) Carbon::now()->diffInDays($this->expiry_date, false);
    }
}
