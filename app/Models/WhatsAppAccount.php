<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppAccount extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_accounts';

    protected $fillable = [
        'user_id',
        'phone_number_id',
        'access_token',
        'business_account_id',
        'verify_token',
        'api_version',
        'is_active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     * access_token is sensitive — never expose in API responses.
     */
    protected $hidden = [
        'access_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'access_token' => 'encrypted',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * The user (tenant) who owns this WhatsApp account.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
