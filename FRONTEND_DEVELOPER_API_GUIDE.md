# Wabiz Frontend Developer API Guide

This document explains the backend flow, module order, and every API endpoint that the frontend needs to integrate. It is written for frontend implementation, so each module explains why the endpoint exists, when to call it, what to send, and what UI behavior to build around it.

## 1. Project Overview

Wabiz is a Laravel 12 backend for a WhatsApp-based business CRM. The frontend should treat it as a multi-tenant SaaS API where each logged-in user owns their own data.

Main features:

- User registration, login, and logout using Laravel Sanctum API tokens.
- One WhatsApp Cloud API account per user.
- Lead management scoped to the authenticated user.
- WhatsApp message history per lead.
- Outgoing WhatsApp message sending.
- Orders connected to leads.
- Scheduled follow-ups connected to leads.
- Message templates with variables.
- Business settings and automation toggles.
- Subscription plans, current subscription, billing history, and payment webhooks.
- Dashboard analytics and chart-ready data.

Important backend rule:

- Most protected data is scoped by `auth()->id()`.
- If the frontend requests another user's lead/order/template/followup, the backend usually returns `404`, not `403`.
- The frontend should show a normal "not found" or redirect state for those cases.

## 2. Base API Rules

Base path:

```text
/api
```

Example full local URL:

```text
http://localhost:8000/api/auth/login
```

All normal JSON API requests should use:

```text
Content-Type: application/json
Accept: application/json
```

Protected endpoints require:

```text
Authorization: Bearer <token>
```

The API response format is mostly:

```json
{
  "status": true,
  "message": "Readable message",
  "data": {}
}
```

Validation errors use HTTP `422`:

```json
{
  "status": false,
  "message": "Validation error",
  "errors": {
    "field_name": ["Error message"]
  }
}
```

Common frontend error handling:

- `401`: token missing, invalid, or expired. Clear local auth state and send user to login.
- `403`: action blocked, for example no active WhatsApp account.
- `404`: resource not found or does not belong to current user.
- `422`: show field-level form errors.
- `500`: show a generic server error message.
- `502`: external WhatsApp API failed.

Pagination:

Laravel paginated endpoints return a pagination object inside `data`, usually with fields like:

- `data`: array of records.
- `current_page`
- `per_page`
- `total`
- `last_page`
- `next_page_url`
- `prev_page_url`

The frontend should keep list filters in URL/query state where possible.

## 3. Recommended Frontend Implementation Order

Build the frontend in this order:

1. Auth module: register, login, token storage, protected route guard, logout.
2. App shell: authenticated layout, API client, global error handling.
3. Billing plans and subscription screen: show plans, subscribe, current usage.
4. WhatsApp account setup: connect/update/disconnect account credentials.
5. Leads module: list, create, edit, detail, statistics.
6. Lead detail tabs: messages, orders, followups.
7. Messages: conversation view and send message.
8. Orders: create order, update status/items, delete pending orders, order stats.
9. Followups: schedule, edit pending, send now, retry/change status.
10. Templates: metadata, CRUD, preview, duplicate, status toggle.
11. Settings: business profile, default templates, logo upload, automation toggles.
12. Dashboard analytics: overview cards and charts.

Why this order:

- Almost every protected feature requires auth first.
- Sending messages, order notifications, and followups depend on a connected WhatsApp account.
- Orders, messages, and followups depend on leads.
- Settings can reference templates, so template screens should exist before template assignment UI.
- Dashboard depends on real data from leads, messages, orders, and followups.

## 4. Auth Module

Auth uses token-based Laravel Sanctum. The frontend stores the returned token and sends it in the `Authorization` header for protected endpoints.

### Register

```text
POST /auth/register
```

Why use it:

- Creates a new user.
- Returns the user and auth token immediately.
- Frontend can log the user in after successful registration.

Body:

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

Success:

- HTTP `201`
- `data.user`
- `data.token`

Frontend steps:

1. Validate password confirmation on UI before sending.
2. On success, store `data.token`.
3. Store basic user profile if needed.
4. Redirect to onboarding, billing, dashboard, or WhatsApp setup.
5. On `422`, show field errors.

### Login

```text
POST /auth/login
```

Why use it:

- Authenticates an existing user by email/password.
- Returns a new token.

Body:

```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

Success:

- HTTP `200`
- `data.user`
- `data.token`

Frontend steps:

1. Send email/password.
2. Store token after success.
3. Redirect into app.
4. On `401`, show "Invalid credentials".
5. On `422`, show validation errors.

### Logout

```text
POST /auth/logout
```

Auth required: yes.

Why use it:

- Revokes the current access token on the backend.
- Clears the active session.

Body: none.

Frontend steps:

1. Call logout with bearer token.
2. Clear token and user state even if the request fails.
3. Redirect to login.

## 5. Billing Module

Billing controls plan visibility, subscription status, usage limits, and payment history.

### Get Plans

```text
GET /billing/plans
```

Auth required: no.

Why use it:

- Show pricing cards before or after login.
- Plans include limits and features.

Returns:

- Array of active subscription plans ordered by price.

Plan fields:

- `id`
- `name`
- `slug`
- `description`
- `price`
- `billing_cycle`
- `max_leads`
- `max_messages`
- `max_orders`
- `features`
- `status`

### Get Current Subscription

```text
GET /billing/subscription
```

Auth required: yes.

Why use it:

- Show the user's active plan.
- Show remaining days and usage counts.
- Use it for billing page and usage banners.

Returns:

- `data.subscription`
- `data.days_remaining`
- `data.usage.leads`
- `data.usage.messages`
- `data.usage.orders`

Each usage object:

```json
{
  "allowed": true,
  "current": 25,
  "limit": 50
}
```

If no active subscription:

- HTTP `404`
- Show plan selection UI.

### Subscribe

```text
POST /billing/subscribe
```

Auth required: yes.

Why use it:

- Starts a new subscription.
- Free plans activate immediately.
- Paid plans become active if `payment_id` is provided, otherwise may be pending.

Body:

```json
{
  "plan_id": 1,
  "gateway": "razorpay",
  "payment_id": "pay_ABC123"
}
```

Allowed `gateway`:

- `razorpay`
- `stripe`
- `manual`

Frontend steps:

1. Fetch plans.
2. User selects plan.
3. If paid, complete payment in the frontend payment gateway first.
4. Send `plan_id`, `gateway`, and gateway `payment_id`.
5. On success, refresh `/billing/subscription`.

### Upgrade Subscription

```text
POST /billing/upgrade
```

Auth required: yes.

Why use it:

- Cancels the current active subscription and creates a new one.

Body:

```json
{
  "plan_id": 2,
  "gateway": "razorpay",
  "payment_id": "pay_XYZ789"
}
```

Frontend steps:

1. Show upgrade confirmation.
2. Complete payment if required.
3. Call upgrade.
4. Refresh subscription and usage.

### Cancel Subscription

```text
POST /billing/cancel
```

Auth required: yes.

Why use it:

- Cancels active subscription.

Body: none.

Frontend steps:

1. Ask for confirmation.
2. Call endpoint.
3. Refresh subscription.
4. If cancelled, show plan selection or limited-access state.

### Renew Subscription

```text
POST /billing/renew
```

Auth required: yes.

Why use it:

- Renews the user's most recent subscription plan.

Body:

```json
{
  "gateway": "razorpay",
  "payment_id": "pay_RENEW456"
}
```

### Billing History

```text
GET /billing/history?per_page=15
```

Auth required: yes.

Why use it:

- Show invoice/payment transaction list.

Query:

- `per_page`: optional, default `15`.

## 6. WhatsApp Account Module

Each user can connect one WhatsApp Cloud API account. The access token is encrypted and hidden in API responses.

The frontend should build this as an onboarding/settings screen.

### Get Connected Account

```text
GET /whatsapp-account
```

Auth required: yes.

Why use it:

- Check whether the user already connected WhatsApp.
- Drive setup state in UI.

Success:

- HTTP `200`
- `data` contains account details except `access_token`.

No account:

- HTTP `404`
- Show connect form.

### Connect Account

```text
POST /whatsapp-account
```

Auth required: yes.

Why use it:

- Saves WhatsApp Cloud API credentials for the user.
- Required before sending messages, followups, and order notifications.

Body:

```json
{
  "phone_number_id": "102345678901234",
  "access_token": "EAAxxxxxxxxx",
  "business_account_id": "109876543210",
  "verify_token": "my_custom_verify_token",
  "api_version": "v21.0"
}
```

Required:

- `phone_number_id`
- `access_token`
- `verify_token`

Optional:

- `business_account_id`
- `api_version` defaults to `v21.0`.

Conflict:

- HTTP `409` if user already has an account.
- Frontend should switch to update flow.

### Update Account

```text
PUT /whatsapp-account
```

Auth required: yes.

Why use it:

- Rotate access token.
- Update phone number ID.
- Enable/disable account.

Body fields:

```json
{
  "phone_number_id": "102345678901234",
  "access_token": "EAAxxxxxxxxx",
  "business_account_id": "109876543210",
  "verify_token": "my_custom_verify_token",
  "api_version": "v21.0",
  "is_active": true
}
```

### Disconnect Account

```text
DELETE /whatsapp-account
```

Auth required: yes.

Why use it:

- Removes WhatsApp credentials.
- After this, sending messages and automation will fail until reconnected.

Frontend steps:

1. Show warning.
2. Delete account.
3. Refresh WhatsApp setup state.

## 7. WhatsApp Webhook Endpoints

These are public endpoints called by Meta, not normal frontend screens.

### Verify Webhook

```text
GET /webhook/whatsapp
```

Auth required: no.

Why it exists:

- Meta verifies the webhook URL by sending a challenge.
- Backend checks whether verify token matches any active WhatsApp account.

Important implementation note:

- The controller currently reads query keys as `hub_mode`, `hub_verify_token`, and `hub_challenge`.
- The Swagger comment mentions dotted names, but the implemented backend uses underscore names.
- Backend/Meta configuration may need alignment.

Expected query:

```text
hub_mode=subscribe
hub_verify_token=<verify_token>
hub_challenge=<challenge>
```

Success:

- Plain text challenge response.

### Incoming WhatsApp Messages

```text
POST /webhook/whatsapp
```

Auth required: no.

Why it exists:

- Receives incoming WhatsApp messages from Meta.
- Finds tenant by `metadata.phone_number_id`.
- Finds or creates a lead for the sender phone.
- Saves incoming message under that lead.

Frontend impact:

- Conversation screens should refresh or poll after expected inbound messages.
- New WhatsApp senders can appear automatically as new leads with `source = whatsapp`.

Supported incoming message text extraction:

- text
- image caption
- video caption
- audio placeholder
- document filename
- sticker placeholder
- location placeholder
- shared contact placeholder
- reaction
- button
- interactive button/list replies

## 8. Leads Module

Leads are the central object. Messages, orders, and followups are attached to leads.

Lead statuses:

- `new`
- `contacted`
- `qualified`
- `negotiation`
- `won`
- `lost`

### List Leads

```text
GET /leads
```

Auth required: yes.

Why use it:

- Main CRM list/table.
- Search/filter/pagination for lead management.

Query:

- `status`: optional lead status.
- `source`: optional source string such as `website` or `whatsapp`.
- `assigned_to`: optional user ID.
- `search`: searches name or phone.
- `per_page`: optional, default `10`.

Frontend UI:

- Search box.
- Status filter.
- Source filter.
- Pagination controls.

### Create Lead

```text
POST /leads
```

Auth required: yes.

Why use it:

- Manually add a customer/lead.

Body:

```json
{
  "name": "Ahmed Khan",
  "phone": "+92300123456",
  "source": "website",
  "status": "new",
  "assigned_to": null,
  "notes": "Important lead"
}
```

Required:

- `name`
- `phone`
- `source`
- `status`

Important:

- Phone must be unique for the current user.
- Another user can have the same phone.

### Get Lead Detail

```text
GET /leads/{id}
```

Auth required: yes.

Why use it:

- Lead detail page.
- Returns lead with `messages`, `orders`, `followups`, and `assignedUser`.

Frontend UI:

- Profile summary.
- Notes.
- Status.
- Tabs for conversation, orders, followups.

### Update Lead

```text
PUT /leads/{id}
```

Auth required: yes.

Why use it:

- Edit profile, phone, source, status, assignee, or notes.

Body fields:

```json
{
  "name": "Ahmed Khan",
  "phone": "+92300123456",
  "source": "website",
  "status": "qualified",
  "assigned_to": null,
  "notes": "Updated note"
}
```

All fields are optional, but at least one should be sent by the frontend.

### Delete Lead

```text
DELETE /leads/{id}
```

Auth required: yes.

Why use it:

- Removes a lead.

Frontend:

- Ask confirmation.
- Consider that related messages/orders/followups may be affected depending on database constraints.

### Lead Statistics

```text
GET /leads-statistics
```

Auth required: yes.

Why use it:

- Small summary widgets for lead pages.

Returns:

- `total_leads`
- `assigned_leads`
- `unassigned_leads`
- `by_status`
- `by_source`

## 9. Messages Module

Messages are stored per lead and can be incoming or outgoing.

Message types:

- `incoming`
- `outgoing`

Message statuses:

- outgoing messages are saved as `sent`.
- incoming webhook messages are saved as `delivered`.

### Get Messages By Lead

```text
GET /messages/{lead_id}
```

Auth required: yes.

Why use it:

- Conversation view for a lead.

Query:

- `type`: optional, `incoming` or `outgoing`.
- `per_page`: optional, default `20`.

Returns:

- `data.lead`
- `data.messages` paginated object

Frontend UI:

- Chat timeline sorted ascending by creation time.
- Message type filter if needed.
- Load more pagination.

### Send WhatsApp Message

```text
POST /messages/send
```

Auth required: yes.

Why use it:

- Sends a WhatsApp text message to a lead using the user's connected WhatsApp account.
- Saves the outgoing message after successful WhatsApp API response.

Body:

```json
{
  "lead_id": 1,
  "message": "Hello! How can we help you?"
}
```

Validation:

- `lead_id` required.
- `message` required, max `4096` characters.

Frontend behavior:

1. Disable send button while request is running.
2. On success, append returned message or refetch messages.
3. On `403`, show "Connect an active WhatsApp account first".
4. On `502`, show WhatsApp delivery/API error.

## 10. Orders Module

Orders belong to leads. Order totals are calculated by the backend from items.

Order statuses:

- `pending`
- `confirmed`
- `packed`
- `shipped`
- `delivered`
- `cancelled`

Important backend behavior:

- New orders start as `pending`.
- Updating status to `shipped` or `delivered` triggers WhatsApp notification if account exists.
- Creating an order also attempts a non-blocking WhatsApp notification.
- Delivered or cancelled orders cannot be updated.
- Only pending orders can be deleted.

### List Orders

```text
GET /orders
```

Auth required: yes.

Why use it:

- Orders table/list across all current user's leads.

Query:

- `status`: optional order status.
- `lead_id`: optional.
- `search`: lead name, phone, or exact order ID.
- `per_page`: optional, default `15`.

Returns:

- Paginated orders with `lead` and `items`.

### Get Orders By Lead

```text
GET /orders/lead/{lead_id}
```

Auth required: yes.

Why use it:

- Lead detail orders tab.

Query:

- `status`: optional.
- `per_page`: optional, default `15`.

Returns:

- `data.lead`
- `data.orders`

### Order Statistics

```text
GET /orders-statistics
```

Auth required: yes.

Why use it:

- Revenue/order summary dashboard or orders page widgets.

Returns:

- `total_revenue`: delivered orders only.
- `total_orders`
- `avg_order_value`
- `by_status`
- `monthly_sales`
- `top_customers`

### Create Order

```text
POST /orders
```

Auth required: yes.

Why use it:

- Create a sale/order for a lead.

Body:

```json
{
  "lead_id": 1,
  "items": [
    {
      "product_name": "Widget Pro",
      "quantity": 2,
      "price": 29.99
    }
  ]
}
```

Backend calculates:

```text
total_price = sum(quantity * price)
```

Frontend:

- Do local total preview for UX.
- Treat backend total as final truth.

### Get Single Order

```text
GET /orders/{id}
```

Auth required: yes.

Why use it:

- Order detail page or edit drawer.

Returns:

- Order with lead and items.

### Update Order

```text
PUT /orders/{id}
```

Auth required: yes.

Why use it:

- Change status.
- Replace item list.

Body:

```json
{
  "status": "shipped",
  "items": [
    {
      "product_name": "Widget Pro",
      "quantity": 3,
      "price": 29.99
    }
  ]
}
```

Important:

- If `items` is sent, backend deletes existing items and recreates the full list.
- Do not send partial item updates.
- Cannot update delivered/cancelled orders.

### Delete Order

```text
DELETE /orders/{id}
```

Auth required: yes.

Why use it:

- Remove a pending order.

Important:

- Only `pending` orders can be deleted.
- For other statuses backend returns `400`.

## 11. Followups Module

Followups schedule WhatsApp messages for a lead. A queued job sends pending followups.

Followup statuses:

- `pending`
- `sent`
- `failed`

Important:

- Only pending followups can be edited or deleted.
- `scheduled_at` must be a future date/time.
- Sending requires active WhatsApp account.

### List Followups

```text
GET /followups
```

Auth required: yes.

Why use it:

- Followup management page.

Query:

- `status`: optional, `pending`, `sent`, `failed`.
- `lead_id`: optional.
- `per_page`: optional, default `15`.

Returns:

- Paginated followups with lead summary.

### Get Followups By Lead

```text
GET /followups/lead/{lead_id}
```

Auth required: yes.

Why use it:

- Lead detail followups tab.

Query:

- `status`: optional.
- `per_page`: optional, default `15`.

Returns:

- `data.lead`
- `data.followups`

### Create Followup

```text
POST /followups
```

Auth required: yes.

Why use it:

- Schedule future WhatsApp followup.

Body:

```json
{
  "lead_id": 1,
  "message": "Hi! Just following up on our conversation.",
  "scheduled_at": "2026-05-16 10:00:00"
}
```

Frontend:

- Use user's selected timezone from settings if available.
- Ensure selected date/time is in the future before sending.

### Update Followup

```text
PUT /followups/{id}
```

Auth required: yes.

Why use it:

- Edit message or scheduled time before it is sent.

Body:

```json
{
  "message": "Updated followup message",
  "scheduled_at": "2026-05-17 10:00:00"
}
```

Important:

- Only pending followups can be updated.

### Delete Followup

```text
DELETE /followups/{id}
```

Auth required: yes.

Why use it:

- Cancel a pending followup.

Important:

- Only pending followups can be deleted.

### Send Followup Now

```text
POST /followups/{id}/send
```

Auth required: yes.

Why use it:

- Manually dispatches a pending followup to the queue immediately.

Frontend:

- Use for "Send now" button.
- After success, refetch followup list.
- Actual final status may change after queue processing.

### Change Followup Status

```text
PATCH /followups/{id}/status
```

Auth required: yes.

Why use it:

- Manually reset failed followup to pending for retry.
- Admin-style correction tool.

Body:

```json
{
  "status": "pending"
}
```

Allowed status:

- `pending`
- `sent`
- `failed`

## 12. Templates Module

Templates store reusable WhatsApp message content with variable syntax.

Variable syntax:

```text
{{variable_name}}
```

Categories:

- `welcome`
- `followup`
- `order`
- `marketing`
- `support`
- `custom`

Statuses:

- `active`
- `inactive`

System variables:

- `name`: Lead/customer name.
- `phone`: Lead phone number.
- `order_id`: Order ID.
- `total`: Order total price.
- `status`: Order or lead status.
- `product`: Product name.
- `date`: Current date.
- `company`: Business/company name.

### List Templates

```text
GET /templates
```

Auth required: yes.

Why use it:

- Template library page.
- Template selector in settings and message composer.

Query:

- `category`: optional.
- `status`: optional.
- `search`: searches name/content.
- `per_page`: optional, default `15`.

### Template Metadata

```text
GET /templates/metadata
```

Auth required: yes.

Why use it:

- Populate category dropdown.
- Show allowed/system variables in template builder.

Returns:

- `categories`
- `system_variables`

### Create Template

```text
POST /templates
```

Auth required: yes.

Why use it:

- Add a reusable message template.

Body:

```json
{
  "name": "Order Shipped",
  "category": "order",
  "content": "Hi {{name}}, your order #{{order_id}} has been shipped! Total: Rs. {{total}}",
  "variables": ["name", "order_id", "total"],
  "status": "active"
}
```

Required:

- `name`
- `category`
- `content`

Optional:

- `variables`: if not sent, backend auto-extracts variables from content.
- `status`: defaults to `active`.

### Get Template

```text
GET /templates/{id}
```

Auth required: yes.

Why use it:

- Edit page.
- Shows extracted variables.

Returns:

- `data.template`
- `data.extracted_variables`

### Update Template

```text
PUT /templates/{id}
```

Auth required: yes.

Why use it:

- Edit template fields.

Body fields:

```json
{
  "name": "Order Delivered",
  "category": "order",
  "content": "Hi {{name}}, your order #{{order_id}} was delivered.",
  "variables": ["name", "order_id"],
  "status": "active"
}
```

Important:

- If content changes and variables are not provided, backend auto-extracts variables.
- If name changes, backend regenerates slug.

### Delete Template

```text
DELETE /templates/{id}
```

Auth required: yes.

Why use it:

- Permanently remove a template.

### Toggle Template Status

```text
PATCH /templates/{id}/toggle-status
```

Auth required: yes.

Why use it:

- Activate/deactivate without editing full template.

Body:

```json
{
  "status": "inactive"
}
```

### Duplicate Template

```text
POST /templates/{id}/duplicate
```

Auth required: yes.

Why use it:

- Quickly create a copy for editing.

Important:

- Duplicate starts as `inactive`.
- Name becomes original name plus `(Copy)`.

### Preview Template

```text
POST /templates/{id}/preview
```

Auth required: yes.

Why use it:

- Render template with sample values before saving/using.

Body:

```json
{
  "sample_data": {
    "name": "John Doe",
    "order_id": "12345",
    "total": "2500.00"
  }
}
```

Returns:

- `original_content`
- `rendered_content`
- `variables_found`
- `sample_data_used`

Frontend:

- Use this in a preview panel.
- Missing variables are rendered as `[variable_name]`.

## 13. Settings Module

Settings store business profile, timezone, default templates, and automation switches.

Default values:

- `timezone`: `Asia/Kolkata`
- `default_country_code`: `+91`
- `auto_followup_enabled`: `true`
- `welcome_message_enabled`: `true`
- `order_notification_enabled`: `true`

### Get Settings

```text
GET /settings
```

Auth required: yes.

Why use it:

- Settings page initial load.
- Auto-creates default settings if missing.
- Also returns selected template summaries and available timezones.

Returns:

- `data.settings`
- `data.templates.welcome`
- `data.templates.followup`
- `data.templates.order`
- `data.available_timezones`

### Update Settings

```text
PUT /settings
```

Auth required: yes.

Why use it:

- Update business profile.
- Set timezone/default country code.
- Assign default templates.
- Enable/disable automation.

Body:

```json
{
  "business_name": "My WhatsApp Store",
  "business_email": "store@example.com",
  "business_phone": "+919876543210",
  "timezone": "Asia/Kolkata",
  "default_country_code": "+91",
  "auto_followup_enabled": true,
  "welcome_message_enabled": true,
  "order_notification_enabled": true,
  "default_welcome_template_id": 1,
  "default_followup_template_id": 2,
  "default_order_template_id": 3
}
```

Important:

- Template IDs must belong to current user.
- If not, backend returns `403`.

### Upload Logo

```text
POST /settings/logo
```

Auth required: yes.

Content type:

```text
multipart/form-data
```

Why use it:

- Upload business logo.

Form field:

- `logo`: image file.

Allowed:

- jpeg
- png
- gif
- svg
- webp

Max size:

- 2 MB.

Returns:

- `business_logo`
- `business_logo_url`

Frontend:

- Use `FormData`.
- Do not send JSON content type for this endpoint.

### Toggle Automation

```text
PATCH /settings/automation
```

Auth required: yes.

Why use it:

- Quick toggle for one automation field.

Body:

```json
{
  "field": "auto_followup_enabled",
  "enabled": false
}
```

Allowed fields:

- `auto_followup_enabled`
- `welcome_message_enabled`
- `order_notification_enabled`

### Reset Settings

```text
POST /settings/reset
```

Auth required: yes.

Why use it:

- Reset settings to defaults.
- Clears business profile, logo, template assignments, and toggles.

Frontend:

- Ask strong confirmation.
- Refetch settings after success.

## 14. Dashboard Analytics Module

Dashboard endpoints are all protected and support the same optional date filters.

Date filter query:

- `period`: `today`, `yesterday`, `last_7_days`, `last_30_days`, `this_month`, `last_month`, `this_year`
- `start_date`: custom start date, use with `end_date`
- `end_date`: custom end date, use with `start_date`

Frontend rule:

- Use either `period` or custom `start_date` plus `end_date`.
- Dates should be `YYYY-MM-DD`.

### Overview

```text
GET /dashboard/overview
```

Why use it:

- Dashboard KPI cards.

Returns:

- `total_leads`
- `total_orders`
- `total_revenue`
- `total_messages`
- `total_followups`
- `pending_followups`
- `delivered_orders`
- `conversion_rate`

### Lead Analytics

```text
GET /dashboard/leads
```

Why use it:

- Lead charts and breakdowns.

Returns:

- `by_status`
- `by_source`
- `daily_growth`
- `monthly_growth`

### Revenue Analytics

```text
GET /dashboard/revenue
```

Why use it:

- Revenue charts and customer value widgets.

Returns:

- `by_status`
- `average_order_value`
- `daily_revenue`
- `monthly_revenue`
- `top_customers`

### Message Analytics

```text
GET /dashboard/messages
```

Why use it:

- Incoming/outgoing message reporting.

Returns:

- `incoming_count`
- `outgoing_count`
- `total_count`
- `messages_per_day`

### Followup Analytics

```text
GET /dashboard/followups
```

Why use it:

- Followup status reporting.

Returns:

- `sent`
- `pending`
- `failed`
- `total`
- `by_day`

### Consolidated Charts

```text
GET /dashboard/charts
```

Why use it:

- One request for chart-ready dashboard data.
- Prefer this endpoint when loading a dashboard with multiple charts.

Returns:

- `lead_funnel`
- `revenue_trend`
- `message_activity`
- `lead_sources`
- `order_status`

## 15. Payment Webhook Endpoints

These are public endpoints called by payment gateways, not normal frontend screens.

### Razorpay Webhook

```text
POST /webhook/razorpay
```

Why it exists:

- Handles Razorpay `payment.captured` and `payment.failed`.
- Activates pending subscription on successful payment.
- Marks failed payments/subscriptions.

Frontend impact:

- After payment, frontend should still call subscribe/upgrade/renew with `payment_id`.
- Also refresh subscription state after gateway completion.

### Stripe Webhook

```text
POST /webhook/stripe
```

Why it exists:

- Handles Stripe `checkout.session.completed`, `payment_intent.succeeded`, and `payment_intent.payment_failed`.

Frontend impact:

- Same as Razorpay: refresh subscription after checkout returns.

## 16. Complete Endpoint Checklist

Public:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/auth/register` | Create user and return token |
| POST | `/auth/login` | Login and return token |
| GET | `/billing/plans` | Public plan listing |
| GET | `/webhook/whatsapp` | Meta webhook verification |
| POST | `/webhook/whatsapp` | Incoming WhatsApp events |
| POST | `/webhook/razorpay` | Razorpay payment events |
| POST | `/webhook/stripe` | Stripe payment events |

Protected:

| Method | Endpoint | Purpose |
| --- | --- | --- |
| POST | `/auth/logout` | Revoke current token |
| GET | `/whatsapp-account` | Get connected WhatsApp account |
| POST | `/whatsapp-account` | Connect WhatsApp account |
| PUT | `/whatsapp-account` | Update WhatsApp credentials |
| DELETE | `/whatsapp-account` | Disconnect WhatsApp account |
| GET | `/leads` | List leads |
| POST | `/leads` | Create lead |
| GET | `/leads/{id}` | Lead detail |
| PUT | `/leads/{id}` | Update lead |
| DELETE | `/leads/{id}` | Delete lead |
| GET | `/leads-statistics` | Lead statistics |
| GET | `/messages/{lead_id}` | Lead message history |
| POST | `/messages/send` | Send WhatsApp message |
| GET | `/orders` | List orders |
| GET | `/orders/lead/{lead_id}` | Orders for a lead |
| GET | `/orders-statistics` | Order statistics |
| POST | `/orders` | Create order |
| GET | `/orders/{id}` | Order detail |
| PUT | `/orders/{id}` | Update order |
| DELETE | `/orders/{id}` | Delete pending order |
| GET | `/followups` | List followups |
| GET | `/followups/lead/{lead_id}` | Followups for a lead |
| POST | `/followups` | Schedule followup |
| PUT | `/followups/{id}` | Update pending followup |
| DELETE | `/followups/{id}` | Delete pending followup |
| POST | `/followups/{id}/send` | Send pending followup now |
| PATCH | `/followups/{id}/status` | Change followup status |
| GET | `/templates` | List templates |
| GET | `/templates/metadata` | Categories and variables |
| POST | `/templates` | Create template |
| GET | `/templates/{id}` | Template detail |
| PUT | `/templates/{id}` | Update template |
| DELETE | `/templates/{id}` | Delete template |
| PATCH | `/templates/{id}/toggle-status` | Activate/deactivate template |
| POST | `/templates/{id}/duplicate` | Duplicate template |
| POST | `/templates/{id}/preview` | Render template preview |
| GET | `/settings` | Get business settings |
| PUT | `/settings` | Update settings |
| POST | `/settings/logo` | Upload business logo |
| PATCH | `/settings/automation` | Toggle one automation field |
| POST | `/settings/reset` | Reset settings |
| GET | `/billing/subscription` | Current subscription and usage |
| POST | `/billing/subscribe` | Subscribe to plan |
| POST | `/billing/upgrade` | Upgrade plan |
| POST | `/billing/cancel` | Cancel subscription |
| POST | `/billing/renew` | Renew subscription |
| GET | `/billing/history` | Payment history |
| GET | `/dashboard/overview` | KPI cards |
| GET | `/dashboard/leads` | Lead analytics |
| GET | `/dashboard/revenue` | Revenue analytics |
| GET | `/dashboard/messages` | Message analytics |
| GET | `/dashboard/followups` | Followup analytics |
| GET | `/dashboard/charts` | Consolidated chart data |

## 17. Suggested Frontend Pages

Auth:

- `/register`
- `/login`

App:

- `/dashboard`
- `/leads`
- `/leads/:id`
- `/orders`
- `/followups`
- `/templates`
- `/settings`
- `/settings/whatsapp`
- `/billing`

Lead detail tabs:

- Overview/profile.
- Messages.
- Orders.
- Followups.

Settings tabs:

- Business profile.
- WhatsApp account.
- Templates and automation.
- Logo.

Billing tabs:

- Current plan.
- Plans.
- Billing history.

## 18. Frontend Integration Notes

Token storage:

- Store token securely according to frontend app standards.
- Add bearer token through a centralized API client.
- On `401`, clear token and redirect to login.

Optimistic UI:

- Use cautious optimistic UI only for simple state changes.
- For sends, orders, followups, and billing, prefer server response/refetch.

WhatsApp dependency:

- Before showing message send UI, check `/whatsapp-account`.
- If missing, show setup CTA.
- `POST /messages/send`, followup sending, and order notifications require an active account.

Date/time:

- `scheduled_at` must be in the future.
- Settings default timezone is `Asia/Kolkata`.
- Keep frontend timezone display consistent with user settings.

Templates:

- Use `/templates/metadata` to build form options.
- Use preview endpoint before sending templated messages.
- Backend can auto-extract variables from content.

Billing:

- Public plan listing can show before login.
- Current subscription controls usage/plan UI.
- Webhooks are backend/gateway concern, but frontend should refresh subscription after payment flow.

Dashboard:

- Use `/dashboard/overview` for KPI cards.
- Use `/dashboard/charts` when multiple charts are needed at once.
- Use specific analytics endpoints for detailed pages.

## 19. Known Backend Behaviors To Respect

- WhatsApp account `access_token` is hidden in API responses.
- One user can connect only one WhatsApp account.
- Lead phone is unique per user.
- Order item updates replace all existing items.
- Delivered/cancelled orders cannot be updated.
- Only pending orders can be deleted.
- Only pending followups can be updated or deleted.
- Followup send-now dispatches a queue job; final send result may happen after the API response.
- Incoming WhatsApp messages can auto-create leads.
- Webhook verification currently reads underscore query parameter names in code.

