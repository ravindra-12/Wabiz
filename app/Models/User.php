<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
         'role'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function leads()
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    /**
     * The WhatsApp account connected by this user (tenant).
     */
    public function whatsappAccount()
    {
        return $this->hasOne(WhatsAppAccount::class);
    }

    /**
     * Leads owned by this user (multi-tenant ownership).
     */
    public function ownedLeads()
    {
        return $this->hasMany(Lead::class, 'user_id');
    }

    /**
     * All subscriptions for this user.
     */
    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * The user's currently active subscription.
     */
    public function activeSubscription()
    {
        return $this->hasOne(UserSubscription::class)
                    ->where('status', 'active')
                    ->where('expiry_date', '>', now())
                    ->latest('id');
    }

    /**
     * All payment transactions for this user.
     */
    public function paymentTransactions()
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * Check if user has an active, non-expired subscription.
     */
    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription()->exists();
    }

    /**
     * Get the user's current subscription plan (or null).
     */
    public function currentPlan(): ?SubscriptionPlan
    {
        $subscription = $this->activeSubscription;

        return $subscription?->plan;
    }
}
