<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'message',
        'type',
        'status'
    ];

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
