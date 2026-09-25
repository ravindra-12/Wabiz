<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\FollowupController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use App\Http\Controllers\Api\WhatsAppAccountController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\TemplateController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\BillingController;

/*
|--------------------------------------------------------------------------
| PUBLIC ROUTES (No Auth Required)
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

/*
|--------------------------------------------------------------------------
| WHATSAPP WEBHOOK ROUTES (No Auth — Meta Cloud API)
|--------------------------------------------------------------------------
*/

Route::prefix('webhook')->group(function () {
    Route::get('/whatsapp', [WhatsAppWebhookController::class, 'verify']);
    Route::post('/whatsapp', [WhatsAppWebhookController::class, 'handleIncoming']);

    // Payment gateway webhooks
    Route::post('/razorpay', [BillingController::class, 'razorpayWebhook']);
    Route::post('/stripe', [BillingController::class, 'stripeWebhook']);
});

/*
|--------------------------------------------------------------------------
| BILLING PLANS (Public — No Auth Required)
|--------------------------------------------------------------------------
*/

Route::get('/billing/plans', [BillingController::class, 'plans']);


/*
|--------------------------------------------------------------------------
| PROTECTED ROUTES (Auth Required)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    // 🔐 Auth
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    // 📱 WhatsApp Account (Multi-tenant)
    Route::get('/whatsapp-account', [WhatsAppAccountController::class, 'show']);
    Route::put('/whatsapp-account', [WhatsAppAccountController::class, 'update']);
    Route::post('/whatsapp-account', [WhatsAppAccountController::class, 'connect']);
    Route::delete('/whatsapp-account', [WhatsAppAccountController::class, 'disconnect']);

    // 🔥 Leads
    Route::get('/leads', [LeadController::class, 'index']);
    Route::post('/leads', [LeadController::class, 'store']);
    Route::get('/leads/{id}', [LeadController::class, 'show']);
    Route::put('/leads/{id}', [LeadController::class, 'update']);
    Route::delete('/leads/{id}', [LeadController::class, 'destroy']);
    Route::get('/leads-statistics', [LeadController::class, 'statistics']);

    // 💬 Messages
    Route::get('/messages/{lead_id}', [MessageController::class, 'getByLead']);
    Route::post('/messages/send', [MessageController::class, 'send']);

    // 📦 Orders
    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/lead/{lead_id}', [OrderController::class, 'getByLead']);
    Route::get('/orders-statistics', [OrderController::class, 'statistics']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{id}', [OrderController::class, 'show']);
    Route::put('/orders/{id}', [OrderController::class, 'update']);
    Route::delete('/orders/{id}', [OrderController::class, 'destroy']);

    // 🔁 Followups
    Route::get('/followups', [FollowupController::class, 'index']);
    Route::get('/followups/lead/{lead_id}', [FollowupController::class, 'getByLead']);
    Route::post('/followups', [FollowupController::class, 'store']);
    Route::put('/followups/{id}', [FollowupController::class, 'update']);
    Route::delete('/followups/{id}', [FollowupController::class, 'destroy']);
    Route::post('/followups/{id}/send', [FollowupController::class, 'sendNow']);
    Route::patch('/followups/{id}/status', [FollowupController::class, 'changeStatus']);

    // 📝 Templates
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::get('/templates/metadata', [TemplateController::class, 'metadata']);
    Route::post('/templates', [TemplateController::class, 'store']);
    Route::get('/templates/{id}', [TemplateController::class, 'show']);
    Route::put('/templates/{id}', [TemplateController::class, 'update']);
    Route::delete('/templates/{id}', [TemplateController::class, 'destroy']);
    Route::patch('/templates/{id}/toggle-status', [TemplateController::class, 'toggleStatus']);
    Route::post('/templates/{id}/duplicate', [TemplateController::class, 'duplicate']);
    Route::post('/templates/{id}/preview', [TemplateController::class, 'preview']);

    // ⚙️ Settings
    Route::get('/settings', [SettingController::class, 'show']);
    Route::put('/settings', [SettingController::class, 'update']);
    Route::post('/settings/logo', [SettingController::class, 'uploadLogo']);
    Route::patch('/settings/automation', [SettingController::class, 'toggleAutomation']);
    Route::post('/settings/reset', [SettingController::class, 'reset']);

    // 💳 Billing & Subscriptions
    Route::prefix('billing')->group(function () {
        Route::get('/subscription', [BillingController::class, 'currentSubscription']);
        Route::post('/subscribe', [BillingController::class, 'subscribe']);
        Route::post('/upgrade', [BillingController::class, 'upgrade']);
        Route::post('/cancel', [BillingController::class, 'cancel']);
        Route::post('/renew', [BillingController::class, 'renew']);
        Route::get('/history', [BillingController::class, 'billingHistory']);
    });

    // 📊 Dashboard Analytics
    Route::prefix('dashboard')->group(function () {
        Route::get('/overview', [DashboardAnalyticsController::class, 'overview']);
        Route::get('/leads', [DashboardAnalyticsController::class, 'leads']);
        Route::get('/revenue', [DashboardAnalyticsController::class, 'revenue']);
        Route::get('/messages', [DashboardAnalyticsController::class, 'messages']);
        Route::get('/followups', [DashboardAnalyticsController::class, 'followups']);
        Route::get('/charts', [DashboardAnalyticsController::class, 'charts']);
    });
});
