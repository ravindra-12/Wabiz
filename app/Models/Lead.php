<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\Model;

class Lead extends Model
{
      use HasFactory;

     protected $fillable = [
        'user_id',
        'name',
        'phone',
        'source',
        'status',
        'assigned_to',
        'notes'
    ];

    // Relationships

    /**
     * The user (tenant) who owns this lead.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

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
