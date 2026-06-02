<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'total_price',
        'status'
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'total_price' => 'decimal:2',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Recalculate total_price from order items.
     */
    public function recalculateTotal(): void
    {
        $this->total_price = $this->items()->sum(
            \Illuminate\Support\Facades\DB::raw('quantity * price')
        );
        $this->save();
    }
}
