<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SettingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Exception;

class SettingController extends Controller
{
    protected SettingService $settingService;

    public function __construct(SettingService $settingService)
    {
        $this->settingService = $settingService;
    }

    /**
     * Get settings
     *
     * @OA\Get(
     *     path="/settings",
     *     tags={"Settings"},
     *     summary="Get business settings",
     *     description="Retrieve the authenticated user's business settings. Auto-creates with defaults if none exist.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Settings fetched successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="settings", type="object",
     *                     @OA\Property(property="id", type="integer"),
     *                     @OA\Property(property="business_name", type="string", nullable=true),
     *                     @OA\Property(property="business_email", type="string", nullable=true),
     *                     @OA\Property(property="business_phone", type="string", nullable=true),
     *                     @OA\Property(property="business_logo", type="string", nullable=true),
     *                     @OA\Property(property="business_logo_url", type="string", nullable=true),
     *                     @OA\Property(property="timezone", type="string", example="Asia/Kolkata"),
     *                     @OA\Property(property="default_country_code", type="string", example="+91"),
     *                     @OA\Property(property="auto_followup_enabled", type="boolean", example=true),
     *                     @OA\Property(property="welcome_message_enabled", type="boolean", example=true),
     *                     @OA\Property(property="order_notification_enabled", type="boolean", example=true)
     *                 ),
     *                 @OA\Property(property="templates", type="object",
     *                     @OA\Property(property="welcome", type="object", nullable=true),
     *                     @OA\Property(property="followup", type="object", nullable=true),
     *                     @OA\Property(property="order", type="object", nullable=true)
     *                 ),
     *                 @OA\Property(property="available_timezones", type="array", @OA\Items(type="string"))
     *             )
     *         )
     *     ),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function show()
    {
        try {
            $setting = $this->settingService->getSettings(auth()->id());
            $setting->load('welcomeTemplate:id,name,slug', 'followupTemplate:id,name,slug', 'orderTemplate:id,name,slug');

            return response()->json([
                'status'  => true,
                'message' => 'Settings fetched successfully',
                'data'    => [
                    'settings' => array_merge($setting->toArray(), [
                        'business_logo_url' => $setting->business_logo
                            ? asset('storage/' . $setting->business_logo)
                            : null,
                    ]),
                    'templates' => [
                        'welcome'  => $setting->welcomeTemplate,
                        'followup' => $setting->followupTemplate,
                        'order'    => $setting->orderTemplate,
                    ],
                    'available_timezones' => SettingService::TIMEZONES,
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error fetching settings',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update settings
     *
     * @OA\Put(
     *     path="/settings",
     *     tags={"Settings"},
     *     summary="Update business settings",
     *     description="Update business profile, timezone, country code, automation toggles, and default template assignments.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="business_name", type="string", example="My WhatsApp Store"),
     *             @OA\Property(property="business_email", type="string", format="email", example="store@example.com"),
     *             @OA\Property(property="business_phone", type="string", example="+919876543210"),
     *             @OA\Property(property="timezone", type="string", example="Asia/Kolkata"),
     *             @OA\Property(property="default_country_code", type="string", example="+91"),
     *             @OA\Property(property="auto_followup_enabled", type="boolean", example=true),
     *             @OA\Property(property="welcome_message_enabled", type="boolean", example=true),
     *             @OA\Property(property="order_notification_enabled", type="boolean", example=true),
     *             @OA\Property(property="default_welcome_template_id", type="integer", nullable=true, example=1),
     *             @OA\Property(property="default_followup_template_id", type="integer", nullable=true, example=2),
     *             @OA\Property(property="default_order_template_id", type="integer", nullable=true, example=3)
     *         )
     *     ),
     *     @OA\Response(response=200, description="Settings updated successfully"),
     *     @OA\Response(response=403, description="Template does not belong to user"),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'business_name'                => 'sometimes|string|max:255',
                'business_email'               => 'sometimes|nullable|email|max:255',
                'business_phone'               => 'sometimes|nullable|string|max:20',
                'timezone'                     => 'sometimes|string|timezone',
                'default_country_code'         => 'sometimes|string|max:5',
                'auto_followup_enabled'        => 'sometimes|boolean',
                'welcome_message_enabled'      => 'sometimes|boolean',
                'order_notification_enabled'   => 'sometimes|boolean',
                'default_welcome_template_id'  => 'sometimes|nullable|integer',
                'default_followup_template_id' => 'sometimes|nullable|integer',
                'default_order_template_id'    => 'sometimes|nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $userId = auth()->id();

            // Validate template ownership for any template IDs provided
            $templateFields = [
                'default_welcome_template_id',
                'default_followup_template_id',
                'default_order_template_id',
            ];

            foreach ($templateFields as $field) {
                if ($request->has($field) && $request->$field !== null) {
                    if (!$this->settingService->validateTemplateOwnership($userId, $request->$field)) {
                        return response()->json([
                            'status'  => false,
                            'message' => "The selected template for {$field} does not belong to you"
                        ], 403);
                    }
                }
            }

            $data = $request->only([
                'business_name',
                'business_email',
                'business_phone',
                'timezone',
                'default_country_code',
                'auto_followup_enabled',
                'welcome_message_enabled',
                'order_notification_enabled',
                'default_welcome_template_id',
                'default_followup_template_id',
                'default_order_template_id',
            ]);

            DB::beginTransaction();

            $setting = $this->settingService->updateSettings($userId, $data);

            DB::commit();

            $setting->load('welcomeTemplate:id,name,slug', 'followupTemplate:id,name,slug', 'orderTemplate:id,name,slug');

            return response()->json([
                'status'  => true,
                'message' => 'Settings updated successfully',
                'data'    => $setting
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error updating settings: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error updating settings',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload business logo
     *
     * @OA\Post(
     *     path="/settings/logo",
     *     tags={"Settings"},
     *     summary="Upload business logo",
     *     description="Upload a business logo image. Max 2MB. Accepts JPEG, PNG, GIF, SVG, WEBP.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\MediaType(mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 required={"logo"},
     *                 @OA\Property(property="logo", type="string", format="binary", description="Business logo image file")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Logo uploaded successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object",
     *                 @OA\Property(property="business_logo", type="string", example="logos/1/abc123.png"),
     *                 @OA\Property(property="business_logo_url", type="string", example="http://localhost/storage/logos/1/abc123.png")
     *             )
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function uploadLogo(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'logo' => 'required|image|mimes:jpeg,png,gif,svg,webp|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $setting = $this->settingService->uploadLogo(auth()->id(), $request->file('logo'));

            return response()->json([
                'status'  => true,
                'message' => 'Logo uploaded successfully',
                'data'    => [
                    'business_logo'     => $setting->business_logo,
                    'business_logo_url' => asset('storage/' . $setting->business_logo),
                ]
            ], 200);

        } catch (Exception $e) {
            Log::error('Logo upload error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error uploading logo',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Toggle automation setting
     *
     * @OA\Patch(
     *     path="/settings/automation",
     *     tags={"Settings"},
     *     summary="Toggle automation settings",
     *     description="Enable or disable individual automation features: follow-up, welcome message, or order notifications.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\RequestBody(required=true,
     *         @OA\JsonContent(
     *             required={"field", "enabled"},
     *             @OA\Property(property="field", type="string", enum={"auto_followup_enabled","welcome_message_enabled","order_notification_enabled"}, example="auto_followup_enabled"),
     *             @OA\Property(property="enabled", type="boolean", example=false)
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Automation setting updated",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=422, description="Validation error"),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function toggleAutomation(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'field'   => 'required|string|in:auto_followup_enabled,welcome_message_enabled,order_notification_enabled',
                'enabled' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status'  => false,
                    'message' => 'Validation error',
                    'errors'  => $validator->errors()
                ], 422);
            }

            $setting = $this->settingService->toggleAutomation(
                auth()->id(),
                $request->field,
                $request->enabled
            );

            return response()->json([
                'status'  => true,
                'message' => 'Automation setting updated',
                'data'    => [
                    'field'   => $request->field,
                    'enabled' => $request->enabled,
                    'settings' => $setting,
                ]
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'status'  => false,
                'message' => 'Error updating automation setting',
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reset settings to defaults
     *
     * @OA\Post(
     *     path="/settings/reset",
     *     tags={"Settings"},
     *     summary="Reset settings to defaults",
     *     description="Reset all settings to factory defaults. Clears business profile, logo, timezone, automation toggles, and template assignments.",
     *     security={{"sanctumAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Settings reset successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="boolean", example=true),
     *             @OA\Property(property="message", type="string"),
     *             @OA\Property(property="data", type="object")
     *         )
     *     ),
     *     @OA\Response(response=500, description="Server error")
     * )
     */
    public function reset()
    {
        try {
            DB::beginTransaction();

            $setting = $this->settingService->resetToDefaults(auth()->id());

            DB::commit();

            return response()->json([
                'status'  => true,
                'message' => 'Settings reset to defaults',
                'data'    => $setting
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();

            Log::error('Error resetting settings: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
            ]);

            return response()->json([
                'status'  => false,
                'message' => 'Error resetting settings',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}
