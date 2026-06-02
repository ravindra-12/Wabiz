<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Followup extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'message',
        'scheduled_at',
        'status'
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * The lead this follow-up belongs to.
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    // ─── Scopes ──────────────────────────────────────────────────

    /**
     * Scope: only pending follow-ups.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope: follow-ups that are due (scheduled_at <= now).
     */
    public function scopeDue($query)
    {
        return $query->where('scheduled_at', '<=', now());
    }
}
