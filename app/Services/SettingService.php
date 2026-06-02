<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\Template;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class SettingService
{
    /**
     * Default settings values used when resetting.
     */
    public const DEFAULTS = [
        'business_name'               => null,
        'business_email'              => null,
        'business_phone'              => null,
        'business_logo'               => null,
        'timezone'                    => 'Asia/Kolkata',
        'default_country_code'        => '+91',
        'auto_followup_enabled'       => true,
        'welcome_message_enabled'     => true,
        'order_notification_enabled'  => true,
        'default_welcome_template_id' => null,
        'default_followup_template_id'=> null,
        'default_order_template_id'   => null,
    ];

    /**
     * Supported timezones (subset — can expand).
     */
    public const TIMEZONES = [
        'Asia/Kolkata',
        'Asia/Dubai',
        'Asia/Karachi',
        'Asia/Dhaka',
        'Asia/Singapore',
        'Asia/Tokyo',
        'Europe/London',
        'Europe/Paris',
        'America/New_York',
        'America/Chicago',
        'America/Los_Angeles',
        'Pacific/Auckland',
        'UTC',
    ];

    /**
     * Get or create settings for a user.
     */
    public function getSettings(int $userId): Setting
    {
        return Setting::forUser($userId);
    }

    /**
     * Update settings for a user.
     *
     * @param int $userId
     * @param array $data Validated update data
     * @return Setting
     */
    public function updateSettings(int $userId, array $data): Setting
    {
        $setting = Setting::forUser($userId);
        $setting->update($data);

        Log::info('Settings updated.', [
            'user_id' => $userId,
            'fields'  => array_keys($data),
        ]);

        return $setting->fresh();
    }

    /**
     * Upload and store business logo.
     *
     * @param int $userId
     * @param UploadedFile $file
     * @return Setting
     */
    public function uploadLogo(int $userId, UploadedFile $file): Setting
    {
        $setting = Setting::forUser($userId);

        // Delete old logo if exists
        if ($setting->business_logo) {
            Storage::disk('public')->delete($setting->business_logo);
        }

        // Store new logo
        $path = $file->store("logos/{$userId}", 'public');

        $setting->update(['business_logo' => $path]);

        Log::info('Business logo uploaded.', [
            'user_id' => $userId,
            'path'    => $path,
        ]);

        return $setting->fresh();
    }

    /**
     * Toggle an automation setting.
     *
     * @param int $userId
     * @param string $field  One of: auto_followup_enabled, welcome_message_enabled, order_notification_enabled
     * @param bool $enabled
     * @return Setting
     */
    public function toggleAutomation(int $userId, string $field, bool $enabled): Setting
    {
        $setting = Setting::forUser($userId);
        $setting->update([$field => $enabled]);

        Log::info('Automation setting toggled.', [
            'user_id' => $userId,
            'field'   => $field,
            'enabled' => $enabled,
        ]);

        return $setting->fresh();
    }

    /**
     * Reset settings to defaults.
     * Deletes the old logo file if present.
     *
     * @param int $userId
     * @return Setting
     */
    public function resetToDefaults(int $userId): Setting
    {
        $setting = Setting::forUser($userId);

        // Delete logo file
        if ($setting->business_logo) {
            Storage::disk('public')->delete($setting->business_logo);
        }

        $setting->update(self::DEFAULTS);

        Log::info('Settings reset to defaults.', ['user_id' => $userId]);

        return $setting->fresh();
    }

    /**
     * Validate that a template belongs to the user before assigning.
     *
     * @param int $userId
     * @param int|null $templateId
     * @return bool
     */
    public function validateTemplateOwnership(int $userId, ?int $templateId): bool
    {
        if (is_null($templateId)) {
            return true; // null = unset, always valid
        }

        return Template::where('id', $templateId)
            ->where('user_id', $userId)
            ->exists();
    }
}
