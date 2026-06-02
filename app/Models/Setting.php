<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'business_name',
        'business_email',
        'business_phone',
        'business_logo',
        'timezone',
        'default_country_code',
        'auto_followup_enabled',
        'welcome_message_enabled',
        'order_notification_enabled',
        'default_welcome_template_id',
        'default_followup_template_id',
        'default_order_template_id',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'auto_followup_enabled'       => 'boolean',
            'welcome_message_enabled'     => 'boolean',
            'order_notification_enabled'  => 'boolean',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────

    /**
     * The user (tenant) who owns these settings.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Default welcome template.
     */
    public function welcomeTemplate()
    {
        return $this->belongsTo(Template::class, 'default_welcome_template_id');
    }

    /**
     * Default follow-up template.
     */
    public function followupTemplate()
    {
        return $this->belongsTo(Template::class, 'default_followup_template_id');
    }

    /**
     * Default order notification template.
     */
    public function orderTemplate()
    {
        return $this->belongsTo(Template::class, 'default_order_template_id');
    }

    // ─── Helpers ─────────────────────────────────────────────────

    /**
     * Get or create settings for a user.
     * Ensures every user always has a settings row.
     */
    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            [
                'timezone' => 'Asia/Kolkata',
                'default_country_code' => '+91',
                'auto_followup_enabled' => true,
                'welcome_message_enabled' => true,
                'order_notification_enabled' => true,
            ]
        );
    }
}
