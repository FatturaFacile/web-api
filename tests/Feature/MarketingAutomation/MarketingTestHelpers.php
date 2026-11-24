<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\MarketingAutomation\Models\Campaign;
use Modules\MarketingAutomation\Models\MarketingConsent;
use Modules\MarketingAutomation\Models\MarketingMessage;

/**
 * Shared fixtures of the Mod 155 feature tests. Required once through require_once.
 *
 * @return array{0: Tenant, 1: User}
 */
function maSetup(string $country = 'CH', string $role = 'sales_manager'): array
{
    $tenant = Tenant::create([
        'name' => 'Mod155 Org '.Str::random(4),
        'legal_name' => 'Mod155 Org SA',
        'country' => $country,
        'default_currency' => $country === 'CH' ? 'CHF' : 'EUR',
    ]);

    $user = User::create([
        'name' => 'Marketing '.Str::random(3),
        'email' => Str::random(10).'@example.ch',
        'password' => bcrypt(Str::password(16)),
        'locale' => 'it',
    ]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid7(), 'role' => $role]);

    return [$tenant, $user];
}

function maActingAs(User $user, Tenant $tenant): mixed
{
    Sanctum::actingAs($user);

    return test()->withHeader('X-Tenant-Id', (string) $tenant->id);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function maContact(Tenant $tenant, array $attributes = []): string
{
    $id = (string) Str::uuid7();
    DB::table('contacts')->insert(array_merge([
        'id' => $id,
        'tenant_id' => (string) $tenant->id,
        'type' => 'customer',
        'country' => $tenant->country,
        'company_name' => 'Cliente '.Str::random(5),
        'email' => Str::lower(Str::random(8)).'@example.ch',
        'phone' => '+4179'.random_int(1000000, 9999999),
        'document_language' => 'it',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attributes));

    return $id;
}

function maInvoice(Tenant $tenant, string $contactId, string $date, float $net, ?string $salesOrderId = null, string $currency = 'CHF', float $paid = 0.0): string
{
    $id = (string) Str::uuid7();
    DB::table('invoices')->insert([
        'id' => $id,
        'tenant_id' => (string) $tenant->id,
        'contact_id' => $contactId,
        'invoice_number' => 'INV-'.Str::upper(Str::random(6)),
        'date' => $date,
        'currency' => $currency,
        'total_net' => $net,
        'total_tax' => 0,
        'total_gross' => $net,
        'total_paid' => $paid,
        'sales_order_id' => $salesOrderId,
        'posted_at' => $date.' 10:00:00',
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
    ]);

    return $id;
}

function maSalesOrder(Tenant $tenant, string $contactId, string $date, float $net, string $currency = 'CHF', ?string $notes = null): string
{
    $id = (string) Str::uuid7();
    DB::table('sales_orders')->insert([
        'id' => $id,
        'tenant_id' => (string) $tenant->id,
        'contact_id' => $contactId,
        'order_number' => 'SO-'.Str::upper(Str::random(6)),
        'date' => $date,
        'currency' => $currency,
        'total_net' => $net,
        'total_tax' => 0,
        'total_gross' => $net,
        'notes' => $notes,
        'created_at' => $date.' 10:00:00',
        'updated_at' => $date.' 10:00:00',
    ]);

    return $id;
}

function maOrderLine(Tenant $tenant, string $orderId, ?string $productId, float $quantity, float $lineTotal, float $costPrice): void
{
    DB::table('sales_order_items')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'sales_order_id' => $orderId,
        'product_id' => $productId,
        'description' => 'Line',
        'quantity' => $quantity,
        'unit_price' => $quantity > 0 ? $lineTotal / $quantity : 0,
        'line_total' => $lineTotal,
        'cost_price' => $costPrice,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * @param  array<string, mixed>  $flags
 */
function maConsent(Tenant $tenant, string $contactId, array $flags = []): MarketingConsent
{
    return MarketingConsent::withoutGlobalScopes()->create(array_merge([
        'tenant_id' => (string) $tenant->id,
        'contact_id' => $contactId,
        'marketing_opt_in' => true,
        'profiling_opt_in' => true,
        'locale' => 'it',
    ], $flags));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function maCampaign(Tenant $tenant, array $attributes = []): Campaign
{
    return Campaign::withoutGlobalScopes()->create(array_merge([
        'tenant_id' => (string) $tenant->id,
        'name' => 'Campagna '.Str::random(4),
        'status' => 'active',
        'channel' => 'email_dem',
        'trigger_type' => 'scheduled_broadcast',
        'sender_name' => 'Mod155 Org SA',
        'sender_email' => 'info@example.ch',
        'sender_postal_address' => 'Via Test 1, 6900 Lugano',
        'provider' => 'smtp',
        'attribution_model' => 'last_touch',
        'attribution_window_days' => 30,
        'currency' => 'CHF',
        'campaign_cost' => 0,
    ], $attributes));
}

/**
 * A sent message of a campaign, optionally already opened / clicked.
 */
function maMessage(Tenant $tenant, Campaign $campaign, string $contactId, ?Carbon $sentAt = null, ?Carbon $clickedAt = null, ?Carbon $openedAt = null, string $status = 'sent'): MarketingMessage
{
    return MarketingMessage::withoutGlobalScopes()->create([
        'tenant_id' => (string) $tenant->id,
        'campaign_id' => $campaign->id,
        'contact_id' => $contactId,
        'token' => Str::random(40),
        'channel' => 'email',
        'recipient' => (string) DB::table('contacts')->where('id', $contactId)->value('email'),
        'locale' => 'it',
        'status' => $status,
        'sent_at' => $sentAt ?? now()->subDays(5),
        'first_clicked_at' => $clickedAt,
        'first_opened_at' => $openedAt ?? $clickedAt,
    ]);
}

/**
 * Runs the callback with the tenant context set, as the queue jobs do.
 */
function maInTenant(Tenant $tenant, Closure $callback): mixed
{
    return app(TenantContext::class)->runWithTenant($tenant, $callback);
}

function maRate(Tenant $tenant, string $from, string $to, string $rate): void
{
    DB::table('exchange_rates')->insert([
        'id' => (string) Str::uuid7(),
        'tenant_id' => (string) $tenant->id,
        'source' => $from,
        'target' => $to,
        'rate' => $rate,
        'effective_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}
