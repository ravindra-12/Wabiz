<?php

namespace App\Http\Controllers\Api;

use OpenApi\Attributes as OA;

#[OA\Info(
    title: "Wabiz CRM API",
    version: "1.0.0",
    description: "Wabiz CRM - Multi-tenant WhatsApp CRM SaaS Platform API"
)]
#[OA\Server(
    url: "http://localhost:8000/api",
    description: "Development Server"
)]
#[OA\SecurityScheme(
    type: "http",
    scheme: "bearer",
    bearerFormat: "token",
    securityScheme: "sanctumAuth"
)]
#[OA\Tag(name: "Authentication", description: "User authentication endpoints")]
#[OA\Tag(name: "Leads", description: "Lead management endpoints")]
#[OA\Tag(name: "Messages", description: "WhatsApp messaging endpoints")]
#[OA\Tag(name: "Orders", description: "Order management endpoints")]
#[OA\Tag(name: "Followups", description: "Follow-up automation endpoints")]
#[OA\Tag(name: "Templates", description: "Message template management endpoints")]
#[OA\Tag(name: "Settings", description: "Business settings endpoints")]
#[OA\Tag(name: "Billing", description: "Subscription and billing endpoints")]
#[OA\Tag(name: "Billing Webhooks", description: "Payment gateway webhook endpoints")]
#[OA\Tag(name: "Dashboard Analytics", description: "Analytics dashboard endpoints")]
#[OA\Tag(name: "WhatsApp Account", description: "WhatsApp account management endpoints")]
#[OA\Tag(name: "WhatsApp Webhook", description: "WhatsApp webhook endpoints")]

/**
 * @OA\Schema(
 *     schema="User",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="John Doe"),
 *     @OA\Property(property="email", type="string", format="email", example="john@example.com"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="Lead",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Ahmed Khan"),
 *     @OA\Property(property="phone", type="string", example="+92300123456"),
 *     @OA\Property(property="source", type="string", example="website"),
 *     @OA\Property(property="status", type="string", enum={"new","contacted","qualified","negotiation","won","lost"}),
 *     @OA\Property(property="assigned_to", type="integer", nullable=true, example=1),
 *     @OA\Property(property="notes", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="ErrorResponse",
 *     @OA\Property(property="status", type="boolean", example=false),
 *     @OA\Property(property="message", type="string", example="Error message"),
 *     @OA\Property(property="error", type="string", nullable=true)
 * )
 *
 * @OA\Schema(
 *     schema="Message",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="lead_id", type="integer", example=1),
 *     @OA\Property(property="message", type="string", example="Hello! How can we help you?"),
 *     @OA\Property(property="type", type="string", enum={"incoming","outgoing"}, example="outgoing"),
 *     @OA\Property(property="status", type="string", enum={"sent","delivered","read","failed"}, example="sent"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="ValidationErrorResponse",
 *     @OA\Property(property="status", type="boolean", example=false),
 *     @OA\Property(property="message", type="string", example="Validation error"),
 *     @OA\Property(property="errors", type="object")
 * )
 *
 * @OA\Schema(
 *     schema="SubscriptionPlan",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="name", type="string", example="Pro"),
 *     @OA\Property(property="slug", type="string", example="pro"),
 *     @OA\Property(property="description", type="string", nullable=true, example="Professional plan with higher limits"),
 *     @OA\Property(property="price", type="number", format="float", example=999.00),
 *     @OA\Property(property="billing_cycle", type="string", enum={"monthly","yearly"}, example="monthly"),
 *     @OA\Property(property="max_leads", type="integer", example=500),
 *     @OA\Property(property="max_messages", type="integer", example=5000),
 *     @OA\Property(property="max_orders", type="integer", example=200),
 *     @OA\Property(property="features", type="array", @OA\Items(type="string"), nullable=true),
 *     @OA\Property(property="status", type="string", enum={"active","inactive"}, example="active"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="UserSubscription",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", example=1),
 *     @OA\Property(property="subscription_plan_id", type="integer", example=2),
 *     @OA\Property(property="payment_gateway", type="string", nullable=true, example="razorpay"),
 *     @OA\Property(property="payment_id", type="string", nullable=true, example="pay_ABC123"),
 *     @OA\Property(property="amount", type="number", format="float", example=999.00),
 *     @OA\Property(property="start_date", type="string", format="date-time"),
 *     @OA\Property(property="expiry_date", type="string", format="date-time"),
 *     @OA\Property(property="status", type="string", enum={"active","expired","cancelled","pending"}, example="active"),
 *     @OA\Property(property="plan", ref="#/components/schemas/SubscriptionPlan"),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 *
 * @OA\Schema(
 *     schema="PaymentTransaction",
 *     @OA\Property(property="id", type="integer", example=1),
 *     @OA\Property(property="user_id", type="integer", example=1),
 *     @OA\Property(property="subscription_id", type="integer", nullable=true, example=1),
 *     @OA\Property(property="gateway", type="string", example="razorpay"),
 *     @OA\Property(property="transaction_id", type="string", nullable=true, example="txn_XYZ789"),
 *     @OA\Property(property="amount", type="number", format="float", example=999.00),
 *     @OA\Property(property="currency", type="string", example="INR"),
 *     @OA\Property(property="status", type="string", enum={"success","failed","pending","refunded"}, example="success"),
 *     @OA\Property(property="response_payload", type="object", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="date-time"),
 *     @OA\Property(property="updated_at", type="string", format="date-time")
 * )
 */
class Swagger
{
    //
}
