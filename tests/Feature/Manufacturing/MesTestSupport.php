<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Manufacturing\Models\MfgAccountingSetting;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgWorkCenter;

/**
 * Two companies, a signing key bound to the first one and a second key bound to both, a work centre, a machine and the
 * helpers below to sign calls like the MES Core does.
 */
function mesSetUp(object $test): void
{
    $test->tenant = Tenant::create(['name' => 'Officina Alfa', 'legal_name' => 'Officina Alfa SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    $test->otherTenant = Tenant::create(['name' => 'Officina Beta', 'legal_name' => 'Officina Beta SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($test->tenant);

    config(['manufacturing_mes.keys' => [
        'k1' => ['secret' => 'secret-one', 'tenants' => [(string) $test->tenant->id]],
        'k2' => ['secret' => 'secret-two', 'tenants' => [(string) $test->tenant->id]],
    ]]);

    $test->workCenter = MfgWorkCenter::factory()->create(['tenant_id' => $test->tenant->id]);
    $test->machine = MfgMachineCenter::factory()->create(['tenant_id' => $test->tenant->id, 'work_center_id' => $test->workCenter->id, 'hourly_rate' => '60.0000']);
}

/**
 * Sends a signed call as the MES Core.
 *
 * @param  array<string, mixed>|null  $body
 * @param  array<string, mixed>  $options  kid, secret, timestamp, tenant (id or null for none), tamper (body signed differently)
 */
function mesCall(object $test, string $method, string $uri, ?array $body = null, array $options = []): TestResponse
{
    $raw = $body !== null ? (string) json_encode($body) : '';
    $signedBody = $options['signed_body'] ?? $raw;
    $timestamp = (int) ($options['timestamp'] ?? time());
    $kid = (string) ($options['kid'] ?? 'k1');
    $secret = (string) ($options['secret'] ?? config("manufacturing_mes.keys.{$kid}.secret", 'unknown'));
    $signature = hash_hmac('sha256', $timestamp.'.'.$signedBody, $secret);
    $tenantId = array_key_exists('tenant', $options) ? $options['tenant'] : (string) $test->tenant->id;

    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_MES_SIGNATURE' => "t={$timestamp},kid={$kid},v1={$signature}",
    ];

    if ($tenantId !== null) {
        $server['HTTP_X_TENANT_ID'] = (string) $tenantId;
    }

    return $test->call($method, $uri, [], [], [], $server, $raw);
}

/**
 * An order of the tenant with one operation on the machine.
 *
 * @param  array<string, mixed>  $order
 * @param  array<string, mixed>  $operation
 */
function mesOperation(object $test, string $status = 'released', array $order = [], array $operation = []): MfgProductionOrderOperation
{
    $productionOrder = MfgProductionOrder::factory()->create(['tenant_id' => $test->tenant->id, 'status' => $status, 'target_quantity' => '100', ...$order]);

    return MfgProductionOrderOperation::factory()->create([
        'tenant_id' => $test->tenant->id,
        'production_order_id' => $productionOrder->id,
        'work_center_id' => $test->workCenter->id,
        'machine_center_id' => $test->machine->id,
        ...$operation,
    ]);
}

/**
 * Makes the tenant take its machine time from the MES.
 */
function mesUseMachineTime(object $test): void
{
    MfgAccountingSetting::query()->updateOrCreate(['tenant_id' => $test->tenant->id], ['machine_time_source' => 'mes']);
}

/**
 * An OEE snapshot event of the MES Core.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mesSnapshotEvent(object $test, MfgProductionOrderOperation $operation, int $revision, array $overrides = []): array
{
    return [
        'event_id' => (string) Str::uuid7(),
        'idempotency_key' => 'k-'.Str::random(8),
        'event_type' => 'OeeOrderCompleted',
        'schema_version' => 1,
        'tenant_id' => (string) $test->tenant->id,
        'machine_id' => (string) $test->machine->id,
        'production_order_operation_id' => (string) $operation->id,
        'scope' => 'Order',
        'scope_key' => (string) $operation->id,
        'revision' => $revision,
        'policy_version' => 1,
        'as_of_event_time' => '2027-03-01T10:00:00Z',
        'occurred_at' => '2027-03-01T10:00:00Z',
        'metrics' => ['run_ms' => 3600000, 'setup_ms' => 600000, 'alarm_ms' => 0, 'down_ms' => 0, 'good_count' => 90, 'scrap_count' => 2],
        ...$overrides,
    ];
}
