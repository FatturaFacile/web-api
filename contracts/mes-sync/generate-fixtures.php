<?php

declare(strict_types=1);

/*
| Regenerates the golden fixtures of the MES <-> Laravel contract (contracts/mes-sync/fixtures).
|
|   php contracts/mes-sync/generate-fixtures.php
|
| The fixtures are COMMITTED and are the single source of truth for both services: the Pest contract tests in
| tests/Feature/Manufacturing/MesContractFixturesTest.php and the xUnit tests in
| services/mes/tests/Mes.Tests/ContractFixtureTests.cs read them. Regenerate only when the contract changes on purpose,
| and review the diff: it is the diff of the wire contract.
|
| Signed envelopes hold the exact raw body (`body`, a string), the signing time (`t`) and the expected header
| (`signature`), so a change of casing, timestamp format or HMAC convention in either service breaks a test.
*/

require __DIR__.'/../../vendor/autoload.php';

use Modules\Manufacturing\Support\MesHmac;

const TENANT = '018f0000-0000-7000-8000-000000000001';
const MACHINE = '018f0000-0000-7000-8000-0000000000a1';
const OPERATION = '018f0000-0000-7000-8000-0000000000b1';
const RUN = '018f0000-0000-7000-8000-0000000000c1';
const KID = 'k1';
const SECRET = 'contract-test-secret';
const SIGNED_AT = 1798000000; // 2026-12-23T04:26:40Z

$dir = __DIR__.'/fixtures';

/**
 * @param  array<string, mixed>  $data
 */
function put(string $dir, string $path, array $data): void
{
    if (! is_dir(dirname($dir.'/'.$path))) {
        mkdir(dirname($dir.'/'.$path), 0777, true);
    }

    file_put_contents($dir.'/'.$path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)."\n");
}

/**
 * A signed request envelope: the raw body bytes, the signing time and the expected X-Mes-Signature value.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function envelope(string $description, string $method, string $path, array $body, array $extra = [], bool $dotnetEscaping = false): array
{
    $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

    if ($dotnetEscaping) {
        // System.Text.Json escapes "+" as u002B in every string it serialises, except in occurred_at, a DateTimeOffset it writes
        // as is. The MES Core signs those exact bytes, so the fixtures carry both forms.
        $escaped = str_replace('+', chr(92).'u002B', $raw);
        $marker = '"occurred_at":"';
        $start = (int) strpos($escaped, $marker) + strlen($marker);
        $end = (int) strpos($escaped, '"', $start);
        $raw = substr($escaped, 0, $start).str_replace(chr(92).'u002B', '+', substr($escaped, $start, $end - $start)).substr($escaped, $end);
    }

    return [
        'description' => $description,
        'method' => $method,
        'path' => $path,
        'tenant_id' => TENANT,
        'kid' => KID,
        'secret' => SECRET,
        't' => SIGNED_AT,
        'body' => $raw,
        'signature' => MesHmac::header(KID, SECRET, $raw, SIGNED_AT),
    ] + $extra;
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function outbox(string $id, string $type, string $dedupe, array $payload, string $at = '2026-12-23T04:26:00.1234567+00:00'): array
{
    // Shape of OutboxRelay.DeliverAsync: {id, type, dedupe_key, occurred_at, payload}.
    return ['id' => $id, 'type' => $type, 'dedupe_key' => $dedupe, 'occurred_at' => $at, 'payload' => $payload];
}

$oee = [
    'planned_ms' => 28800000, 'run_ms' => 25200000, 'setup_ms' => 1200000, 'alarm_ms' => 600000, 'down_ms' => 1200000,
    'unknown_ms' => 600000, 'ideal_work_ms' => 21000000, 'ideal_good_ms' => 20500000, 'total_count' => 17500,
    'good_count' => 17100, 'has_quality_data' => true, 'availability' => 0.875, 'performance' => 0.8333,
    'quality' => 0.9762, 'oee' => 0.7118, 'coverage_pct' => 100.0, 'null_reasons' => [],
];

// ── MES Core -> Laravel (POST /api/v1/manufacturing/mes-events, answered with 207) ─────────────────────────
$events = [
    'oee-shift-closed' => [outbox('018f0000-0000-7000-8000-0000000000d1', 'OeeShiftClosed', 'shift-closed:'.MACHINE.':2026-12-22/A', [
        'machine_id' => MACHINE, 'shift_ref' => '2026-12-22/A', 'start' => '2026-12-22T06:00:00+00:00', 'end' => '2026-12-22T14:00:00+00:00', 'policy_version' => 1, 'oee' => $oee,
    ]), 'applied'],
    'oee-snapshot-revised-window' => [outbox('018f0000-0000-7000-8000-0000000000d2', 'OeeSnapshotRevised', 'revise:'.MACHINE.':2026-12-22T06:00:00+00:00:2026-12-22T07:00:00+00:00:3600000:3000000:0:0:0:0:120:1', [
        'machine_id' => MACHINE, 'window_start' => '2026-12-22T06:00:00+00:00', 'window_end' => '2026-12-22T07:00:00+00:00', 'policy_version' => 1, 'oee' => $oee,
    ]), 'applied'],
    'oee-order-completed' => [outbox('018f0000-0000-7000-8000-0000000000d3', 'OeeOrderCompleted', 'order-completed:'.RUN, [
        'run_id' => RUN, 'work_order_ref' => OPERATION, 'machine_id' => MACHINE, 'total' => 500, 'scrap' => 12, 'ended_at' => '2026-12-22T13:30:00+00:00',
    ]), 'applied'],
    'production-count-checkpoint' => [outbox('018f0000-0000-7000-8000-0000000000d4', 'ProductionCountCheckpoint', 'ckpt:'.MACHINE.':batch-1', [
        'machine_id' => MACHINE, 'at' => '2026-12-22T09:15:00+00:00', 'total' => 240, 'good' => 236, 'work_order_ref' => OPERATION, 'run_id' => RUN,
    ]), 'applied'],
    'oee-snapshot-revised-scrap' => [outbox('018f0000-0000-7000-8000-0000000000d6', 'OeeSnapshotRevised', 'scrap:'.RUN.':14', [
        'machine_id' => MACHINE, 'run_id' => RUN, 'work_order_ref' => OPERATION, 'total' => 500, 'scrap_total' => 14, 'at' => '2026-12-22T13:31:00+00:00',
    ], '2026-12-23T04:27:00.1234567+00:00'), 'applied'],
    'machine-alarm-raised' => [outbox('018f0000-0000-7000-8000-0000000000d5', 'MachineAlarmRaised', 'alarm:'.MACHINE.':2026-12-22T10:00:00.0000000+00:00', [
        'machine_id' => MACHINE, 'raised_at' => '2026-12-22T10:00:00+00:00',
    ]), 'applied'],
];

foreach ($events as $name => [$event, $status]) {
    put($dir, "mes-to-laravel/{$name}.json", envelope(
        "A batch with one {$event['type']} message as the OutboxRelay of the MES Core sends it.",
        'POST',
        '/api/v1/manufacturing/mes-events',
        ['events' => [$event]],
        ['expect' => ['http' => 207, 'first_delivery' => [['id' => $event['id'], 'status' => $status]], 'redelivery' => [['id' => $event['id'], 'status' => 'duplicate']]]],
        true,
    ));
}

// ── Laravel -> MES Core ───────────────────────────────────────────────────────────────────────────────────
// Laravel pushes payload-less nudges; the MES Core answers by pulling the authoritative feed (see
// MesReferenceEventFactory). `id` names the aggregate of the notification: an order, a work centre, a reason code.
put($dir, 'laravel-to-mes/ref-event.work-order-released.json', envelope(
    'Nudge after a production order was released to POST /api/v1/mes/ref-events (read by Program.RefEventDto): no payload, the MES Core pulls work-orders.',
    'POST',
    '/api/v1/mes/ref-events',
    ['tenant_id' => TENANT, 'resource' => 'work-orders', 'id' => '018f0000-0000-7000-8000-0000000000e1', 'seq' => 7, 'revision' => 7, 'deleted' => false, 'payload' => null],
));

put($dir, 'laravel-to-mes/ref-event.shift-calendar-updated.json', envelope(
    'Nudge after the shift instances of a work centre were re-materialised: the id is the work centre, the MES Core pulls shift-calendars.',
    'POST',
    '/api/v1/mes/ref-events',
    ['tenant_id' => TENANT, 'resource' => 'shift-calendars', 'id' => '018f0000-0000-7000-8000-0000000000f1', 'seq' => 12, 'revision' => 12, 'deleted' => false, 'payload' => null],
));

put($dir, 'laravel-to-mes/feed.work-orders.json', [
    'description' => 'GET .../mes-sync/work-orders: one released operation with target cycle time and routing parameters.',
    'path' => '/api/v1/manufacturing/mes-sync/work-orders?after_seq=0&limit=500',
    'tenant_id' => TENANT,
    'response' => [
        'schema_version' => 1, 'resource' => 'work-orders',
        'data' => [[
            'id' => OPERATION, 'seq' => 7, 'revision' => 7, 'deleted' => false,
            'payload' => [
                'production_order_operation_id' => OPERATION, 'work_order_id' => '018f0000-0000-7000-8000-0000000000e1', 'order_number' => 'PO-2026-0042',
                'operation_sequence' => 10, 'machine_id' => MACHINE, 'work_center_id' => '018f0000-0000-7000-8000-0000000000f1',
                'item_id' => '018f0000-0000-7000-8000-000000000101', 'item_sku' => 'GEAR-42', 'planned_quantity' => '500.0000',
                'ideal_cycle_time_ms' => 30000.0, 'ict_source' => 'derived', 'setup_standard_ms' => 1800000,
                'scheduled_start' => '2026-12-22', 'scheduled_end' => '2026-12-23', 'status' => 'Released', 'erp_status_raw' => 'released',
            ],
        ]],
        'next_seq' => 7, 'has_more' => false, 'watermark' => 7,
    ],
]);

$machinePayload = [
    'machine_id' => MACHINE, 'machine_code' => 'CNC-01', 'name' => 'CNC lathe 1', 'work_center_id' => '018f0000-0000-7000-8000-0000000000f1', 'asset_id' => null, 'power_kw' => '15.50', 'status' => 'active',
    'line_code' => 'WC-LINE-A', 'ideal_cycle_ms' => 1200.0,
    'tag_roles' => ['run' => 'Running', 'alarm' => 'Alarm', 'count' => 'TotalCounter', 'good' => 'GoodCounter'], 'debounce_ms' => 3000, 'stale_after_s' => 30,
    'counter' => ['rollover_modulus' => 65536, 'max_delta_per_sample' => 500], 'oee_policy' => ['version' => 1, 'setup' => 'AvailabilityLoss', 'gap' => 'ExcludeFromPlanned'],
];
$shiftPayload = ['scope_type' => 'work_center', 'scope_id' => '018f0000-0000-7000-8000-0000000000f1', 'shift_code' => 'DAY', 'starts_at' => '2026-12-22T05:00:00Z', 'ends_at' => '2026-12-22T13:00:00Z', 'timezone' => 'Europe/Rome', 'non_production' => [
    ['kind' => 'break', 'machine_id' => null, 'from' => '2026-12-22T09:00:00Z', 'to' => '2026-12-22T09:15:00Z', 'reference' => null],
    ['kind' => 'planned_maintenance', 'machine_id' => MACHINE, 'from' => '2026-12-22T11:00:00Z', 'to' => '2026-12-22T13:00:00Z', 'reference' => 'MWO-2026-0007'],
]];

put($dir, 'laravel-to-mes/feed.machines.json', [
    'description' => 'GET /api/v1/manufacturing/mes-sync/machines?after_seq=0&limit=2 (page with a live machine and a tombstone).',
    'path' => '/api/v1/manufacturing/mes-sync/machines?after_seq=0&limit=2',
    'tenant_id' => TENANT,
    'response' => [
        'schema_version' => 1, 'resource' => 'machines',
        'data' => [
            ['id' => MACHINE, 'seq' => 3, 'revision' => 3, 'deleted' => false, 'payload' => $machinePayload],
            ['id' => '018f0000-0000-7000-8000-0000000000a2', 'seq' => 5, 'revision' => 5, 'deleted' => true, 'payload' => new stdClass],
        ],
        'next_seq' => 5, 'has_more' => false, 'watermark' => 5,
    ],
]);

put($dir, 'laravel-to-mes/feed.shift-calendars.json', [
    'description' => 'GET .../mes-sync/shift-calendars: dated UTC shift instances with the breaks of the plant and the planned maintenance of one machine (non_production).',
    'path' => '/api/v1/manufacturing/mes-sync/shift-calendars?after_seq=0&limit=500',
    'tenant_id' => TENANT,
    'response' => [
        'schema_version' => 1, 'resource' => 'shift-calendars',
        'data' => [['id' => '018f0000-0000-7000-8000-000000000201', 'seq' => 11, 'revision' => 11, 'deleted' => false, 'payload' => $shiftPayload]],
        'next_seq' => 11, 'has_more' => false, 'watermark' => 11,
    ],
]);

put($dir, 'laravel-to-mes/manifest.machines.json', [
    'description' => 'GET .../mes-sync/machines/manifest: live entities only, as {id, revision}.',
    'path' => '/api/v1/manufacturing/mes-sync/machines/manifest',
    'tenant_id' => TENANT,
    'response' => ['schema_version' => 1, 'resource' => 'machines', 'items' => [['id' => MACHINE, 'revision' => 3]], 'next_cursor' => null, 'has_more' => false, 'watermark' => 5],
]);

put($dir, 'laravel-to-mes/manifest.machines.paged.json', [
    'description' => 'The manifest is paged by an opaque cursor: the MES Core must read every page before it decides what is orphaned.',
    'tenant_id' => TENANT,
    'pages' => [
        ['path' => '/api/v1/manufacturing/mes-sync/machines/manifest?limit=1', 'response' => ['schema_version' => 1, 'resource' => 'machines', 'items' => [['id' => MACHINE, 'revision' => 3]], 'next_cursor' => 'eyJ2IjoxLCJlbnRpdHkiOiIwMThmMDAwMC0wMDAwLTcwMDAtODAwMC0wMDAwMDAwMDAwYTEifQ', 'has_more' => true, 'watermark' => 8]],
        ['path' => '/api/v1/manufacturing/mes-sync/machines/manifest?limit=1&cursor=eyJ2IjoxLCJlbnRpdHkiOiIwMThmMDAwMC0wMDAwLTcwMDAtODAwMC0wMDAwMDAwMDAwYTEifQ', 'response' => ['schema_version' => 1, 'resource' => 'machines', 'items' => [['id' => '018f0000-0000-7000-8000-0000000000a3', 'revision' => 8]], 'next_cursor' => null, 'has_more' => false, 'watermark' => 8]],
    ],
]);

echo "Fixtures written to {$dir}\n";
