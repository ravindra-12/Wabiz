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

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }
}
