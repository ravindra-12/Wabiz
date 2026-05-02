<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
      use HasFactory;

     protected $fillable = [
        'name',
        'phone',
        'source',
        'status',
        'assigned_to',
        'notes'
    ];

    // Relationships
    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function followups()
    {
        return $this->hasMany(Followup::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
}
