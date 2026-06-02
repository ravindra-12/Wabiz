<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'subscription_id',
        'gateway',
        'transaction_id',
        'amount',
        'currency',
        'status',
        'response_payload',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'amount'           => 'decimal:2',
            'response_payload' => 'array',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * The user (tenant) who made this payment.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The subscription this payment is for.
     */
    public function subscription()
    {
        return $this->belongsTo(UserSubscription::class, 'subscription_id');
    }

    // ─── Scopes ─────────────────────────────────────────────────

    /**
     * Filter successful transactions.
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }
}
