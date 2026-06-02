<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_name',
        'quantity',
        'price'
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // ─── Accessors ───────────────────────────────────────────────

    /**
     * Get the line total (quantity × price).
     */
    public function getLineTotalAttribute(): float
    {
        return $this->quantity * $this->price;
    }
}
