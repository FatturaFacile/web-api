<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Modules\Manufacturing\Jobs\PushMesReferenceChangeJob;
use Modules\Manufacturing\Models\MfgMachineCenter;
use Modules\Manufacturing\Models\MfgProductionOrder;
use Modules\Manufacturing\Models\MfgProductionOrderOperation;
use Modules\Manufacturing\Models\MfgWorkCenter;
use Modules\Manufacturing\Support\MesHmac;

/*
| Cross-service contract: the golden fixtures in contracts/mes-sync/fixtures are shared with the MES Core's xUnit tests
| (services/mes/tests/Mes.Tests/ContractFixtureTests.cs). A change of field casing, timestamp format, envelope or HMAC
| convention in either service fails one of the two suites. Regenerate on purpose with
| `php contracts/mes-sync/generate-fixtures.php` and review the diff.
*/

uses(RefreshDatabase::class);

const MES_FIXTURES = __DIR__.'/../../../contracts/mes-sync/fixtures';
const MES_FIXTURE_TENANT = '018f0000-0000-7000-8000-000000000001';
const MES_FIXTURE_MACHINE = '018f0000-0000-7000-8000-0000000000a1';
const MES_FIXTURE_OPERATION = '018f0000-0000-7000-8000-0000000000b1';
const MES_FIXTURE_WORK_CENTER = '018f0000-0000-7000-8000-0000000000f1';

/**
 * @return array<string, mixed>
 */
function mesFixture(string $path): array
{
    /** @var array<string, mixed> $fixture */
    $fixture = json_decode((string) file_get_contents(MES_FIXTURES.'/'.$path), true, 512, JSON_THROW_ON_ERROR);

    return $fixture;
}

/**
 * @return list<string>
 */
function mesFixtureFiles(string $folder): array
{
    $files = glob(MES_FIXTURES.'/'.$folder.'/*.json');

    return array_map(static fn (string $file): string => $folder.'/'.basename($file), $files !== false ? $files : []);
}

/**
 * The structure of a value: every key (recursively), with the JSON type of every leaf (int and float are both a number).
 *
 * @return array<string, string>
 */
function mesShape(mixed $value, string $prefix = ''): array
{
    if (is_array($value) && $value !== [] && ! array_is_list($value)) {
        $shape = [];
        foreach ($value as $key => $child) {
            $shape += mesShape($child, $prefix.'.'.$key);
        }

        return $shape;
    }

    if (is_array($value) && array_is_list($value) && $value !== []) {
        return mesShape($value[0], $prefix.'[]');
    }

    $type = get_debug_type($value);

    return [$prefix => is_array($value) ? 'array' : (in_array($type, ['int', 'float'], true) ? 'number' : $type)];
}

/**
 * The tenant, machine and operation the fixtures talk about, and the key the fixtures are signed with.
 */
function mesFixtureSetUp(object $test): void
{
    $tenant = new Tenant(['name' => 'Officina Alfa', 'legal_name' => 'Officina Alfa SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    $tenant->forceFill(['id' => MES_FIXTURE_TENANT])->save();
    $test->tenant = $tenant;
    $test->otherTenant = Tenant::create(['name' => 'Officina Beta', 'legal_name' => 'Officina Beta SpA', 'country' => 'IT', 'default_currency' => 'EUR']);
    app(TenantContext::class)->setTenant($tenant);

    $fixture = mesFixture('mes-to-laravel/oee-shift-closed.json');
    config(['manufacturing_mes.keys' => [(string) $fixture['kid'] => ['secret' => (string) $fixture['secret'], 'tenants' => [MES_FIXTURE_TENANT]]]]);

    $workCenter = MfgWorkCenter::factory()->create(['id' => MES_FIXTURE_WORK_CENTER, 'tenant_id' => MES_FIXTURE_TENANT]);
    $test->machine = MfgMachineCenter::factory()->create([
        'id' => MES_FIXTURE_MACHINE, 'tenant_id' => MES_FIXTURE_TENANT, 'work_center_id' => $workCenter->id,
        'machine_code' => 'CNC-01', 'name' => 'CNC lathe 1', 'power_kw' => '15.5', 'hourly_rate' => '60',
    ]);
    $order = MfgProductionOrder::factory()->create(['tenant_id' => MES_FIXTURE_TENANT, 'status' => 'released', 'target_quantity' => '500']);
    $test->operation = MfgProductionOrderOperation::factory()->create([
        'id' => MES_FIXTURE_OPERATION, 'tenant_id' => MES_FIXTURE_TENANT, 'production_order_id' => $order->id,
        'work_center_id' => $workCenter->id, 'machine_center_id' => $test->machine->id,
    ]);
}

/**
 * Sends a signed envelope of a fixture exactly as stored: same bytes, same header.
 *
 * @param  array<string, mixed>  $fixture
 * @param  array<string, mixed>  $override  body, signature, tenant
 */
function mesSendFixture(object $test, array $fixture, array $override = []): TestResponse
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_MES_SIGNATURE' => (string) ($override['signature'] ?? $fixture['signature']),
        'HTTP_X_TENANT_ID' => (string) ($override['tenant'] ?? $fixture['tenant_id']),
    ];

    return $test->call((string) $fixture['method'], (string) $fixture['path'], [], [], [], $server, (string) ($override['body'] ?? $fixture['body']));
}

beforeEach(function (): void {
    mesFixtureSetUp($this);
    $this->travelTo(Carbon::createFromTimestampUTC((int) mesFixture('mes-to-laravel/oee-shift-closed.json')['t']));
});

test('every fixture carries the signature this API computes for its exact body', function (string $file): void {
    $fixture = mesFixture($file);

    expect($fixture['signature'])->toBe(MesHmac::header((string) $fixture['kid'], (string) $fixture['secret'], (string) $fixture['body'], (int) $fixture['t']))
        ->and(json_decode((string) $fixture['body'], true))->toBeArray();
})->with([...mesFixtureFiles('mes-to-laravel'), 'laravel-to-mes/ref-event.work-order-released.json', 'laravel-to-mes/ref-event.shift-calendar-updated.json']);

test('the message of the MES Core is applied once and a redelivery is acknowledged as a duplicate without a second posting', function (string $file): void {
    $fixture = mesFixture($file);
    $expect = (array) $fixture['expect'];

    $first = mesSendFixture($this, $fixture)->assertStatus((int) $expect['http'])->json('results');
    $inbox = DB::table('mfg_mes_inbox')->count();
    $snapshots = DB::table('mfg_mes_oee_snapshot_revisions')->count();
    $proposals = DB::table('mfg_mes_count_proposals')->count();

    $second = mesSendFixture($this, $fixture)->assertStatus(207)->json('results');

    expect(collect($first)->map(fn (array $r): array => ['id' => $r['id'], 'status' => $r['status']])->all())->toBe($expect['first_delivery'])
        ->and(collect($second)->map(fn (array $r): array => ['id' => $r['id'], 'status' => $r['status']])->all())->toBe($expect['redelivery'])
        ->and($inbox)->toBe(1)
        ->and(DB::table('mfg_mes_inbox')->count())->toBe($inbox)
        ->and(DB::table('mfg_mes_oee_snapshot_revisions')->count())->toBe($snapshots)
        ->and(DB::table('mfg_mes_count_proposals')->count())->toBe($proposals);
})->with(mesFixtureFiles('mes-to-laravel'));

test('the completed order of the MES Core becomes one count proposal for the operation, whatever the number of deliveries', function (): void {
    $fixture = mesFixture('mes-to-laravel/oee-order-completed.json');

    mesSendFixture($this, $fixture)->assertStatus(207);
    mesSendFixture($this, $fixture)->assertStatus(207);

    $snapshot = DB::table('mfg_mes_oee_snapshots')->first();
    $metrics = json_decode((string) $snapshot->metrics, true);

    expect(DB::table('mfg_mes_count_proposals')->count())->toBe(1)
        ->and($snapshot->production_order_operation_id)->toBe(MES_FIXTURE_OPERATION)
        ->and($snapshot->scope)->toBe('Order')
        ->and($metrics)->toBe(['good_count' => 488, 'scrap_count' => 12]);
});

test('a tampered body is refused with 401 and nothing is stored', function (): void {
    $fixture = mesFixture('mes-to-laravel/oee-order-completed.json');

    mesSendFixture($this, $fixture, ['body' => str_replace('"scrap":12', '"scrap":0', (string) $fixture['body'])])
        ->assertUnauthorized()->assertJsonPath('code', 'mes.signature.invalid');

    expect(DB::table('mfg_mes_inbox')->count())->toBe(0);
});

test('a timestamp outside the replay window is refused in both directions and one inside it is accepted', function (): void {
    $fixture = mesFixture('mes-to-laravel/machine-alarm-raised.json');
    $signedAt = Carbon::createFromTimestampUTC((int) $fixture['t']);

    $this->travelTo($signedAt->copy()->addSeconds(301));
    mesSendFixture($this, $fixture)->assertUnauthorized()->assertJsonPath('code', 'mes.signature.expired');

    $this->travelTo($signedAt->copy()->subSeconds(301));
    mesSendFixture($this, $fixture)->assertUnauthorized()->assertJsonPath('code', 'mes.signature.expired');

    $this->travelTo($signedAt->copy()->addSeconds(299));
    mesSendFixture($this, $fixture)->assertStatus(207);
});

test('a valid signature never reaches another tenant than the ones of its key', function (): void {
    $fixture = mesFixture('mes-to-laravel/machine-alarm-raised.json');

    mesSendFixture($this, $fixture, ['tenant' => (string) $this->otherTenant->id])->assertForbidden()->assertJsonPath('code', 'mes.tenant.forbidden');

    expect(DB::table('mfg_mes_inbox')->count())->toBe(0);
});

test('a message of an unsupported shape is rejected on its own and does not fail the batch', function (): void {
    $fixture = mesFixture('mes-to-laravel/oee-snapshot-revised-window.json');
    $good = json_decode((string) $fixture['body'], true)['events'][0];
    $scrapOnly = ['id' => '018f0000-0000-7000-8000-0000000000d9', 'type' => 'OeeSnapshotRevised', 'dedupe_key' => 'scrap:run:1', 'occurred_at' => $good['occurred_at'], 'payload' => ['machine_id' => MES_FIXTURE_MACHINE, 'run_id' => '018f0000-0000-7000-8000-0000000000c1', 'scrap_total' => 3, 'at' => '2026-12-22T10:00:00+00:00']];

    $body = (string) json_encode(['events' => [$scrapOnly, $good]], JSON_UNESCAPED_SLASHES);
    $signature = MesHmac::header((string) $fixture['kid'], (string) $fixture['secret'], $body, (int) $fixture['t']);

    $results = mesSendFixture($this, $fixture, ['body' => $body, 'signature' => $signature])->assertStatus(207)->json('results');

    expect(collect($results)->pluck('status')->all())->toBe(['rejected', 'applied'])
        ->and($results[0]['message'])->toContain('event.unsupported_shape');
});

test('the push to the MES Core is byte-identical to its fixtures, signature included', function (string $file, string $resource, string $id, int $seq): void {
    config(['manufacturing_mes.core' => ['base_url' => 'https://mes.test', 'key_id' => 'k1', 'secret' => 'contract-test-secret', 'timeout_seconds' => 5, 'push_tries' => 3]]);
    Http::fake(['https://mes.test/*' => Http::response('', 202)]);

    dispatch_sync(new PushMesReferenceChangeJob(MES_FIXTURE_TENANT, $resource, $id, $seq));

    $fixture = mesFixture($file);

    Http::assertSent(fn (HttpRequest $request): bool => $request->url() === 'https://mes.test'.$fixture['path']
        && $request->method() === $fixture['method']
        && $request->header('X-Tenant-Id') === [MES_FIXTURE_TENANT]
        && $request->body() === $fixture['body']
        && $request->header(MesHmac::Header) === [$fixture['signature']]);
})->with([
    'work order released' => ['laravel-to-mes/ref-event.work-order-released.json', 'work-orders', '018f0000-0000-7000-8000-0000000000e1', 7],
    'shift calendar updated' => ['laravel-to-mes/ref-event.shift-calendar-updated.json', 'shift-calendars', MES_FIXTURE_WORK_CENTER, 12],
]);

test('nothing is pushed while the MES Core is not configured', function (): void {
    config(['manufacturing_mes.core.base_url' => '']);
    Http::fake();

    dispatch_sync(new PushMesReferenceChangeJob(MES_FIXTURE_TENANT, 'machines', MES_FIXTURE_MACHINE, 3));

    Http::assertNothingSent();
});

test('a failing MES Core makes the push job fail so it is retried', function (): void {
    config(['manufacturing_mes.core' => ['base_url' => 'https://mes.test', 'key_id' => 'k1', 'secret' => 'contract-test-secret', 'timeout_seconds' => 5, 'push_tries' => 3]]);
    Http::fake(['https://mes.test/*' => Http::response('boom', 503)]);

    expect(fn () => dispatch_sync(new PushMesReferenceChangeJob(MES_FIXTURE_TENANT, 'machines', MES_FIXTURE_MACHINE, 3)))->toThrow(RuntimeException::class);
});

test('the pull feed of machines has the shape of its fixture', function (): void {
    $fixture = mesFixture('laravel-to-mes/feed.machines.json');

    $page = mesSendFixture($this, ['method' => 'GET', 'path' => '/api/v1/manufacturing/mes-sync/machines', 'body' => '', 'tenant_id' => MES_FIXTURE_TENANT, 'signature' => MesHmac::header('k1', 'contract-test-secret', '', 1798000000)])
        ->assertOk()->json();

    $expected = $fixture['response'];

    expect(mesShape($page['data'][0]))->toBe(mesShape($expected['data'][0]))
        ->and(array_keys($page))->toBe(array_keys($expected))
        ->and($page['data'][0]['payload']['machine_id'])->toBe(MES_FIXTURE_MACHINE)
        ->and($page['data'][0]['payload']['machine_code'])->toBe('CNC-01')
        ->and($page['has_more'])->toBeFalse();
});

test('the manifest lists id and revision of the live entities like its fixture', function (): void {
    $fixture = mesFixture('laravel-to-mes/manifest.machines.json');

    $manifest = mesSendFixture($this, ['method' => 'GET', 'path' => '/api/v1/manufacturing/mes-sync/machines/manifest', 'body' => '', 'tenant_id' => MES_FIXTURE_TENANT, 'signature' => MesHmac::header('k1', 'contract-test-secret', '', 1798000000)])
        ->assertOk()->json();

    expect(array_keys($manifest))->toBe(array_keys($fixture['response']))
        ->and(mesShape($manifest['items'][0]))->toBe(mesShape($fixture['response']['items'][0]));
});

test('the shift calendar feed has the shape of its fixture', function (): void {
    $fixture = mesFixture('laravel-to-mes/feed.shift-calendars.json');
    $expected = $fixture['response']['data'][0]['payload'];

    DB::table('mfg_shift_instances')->insert([
        'id' => '018f0000-0000-7000-8000-000000000201', 'tenant_id' => MES_FIXTURE_TENANT, 'scope_type' => $expected['scope_type'], 'scope_id' => MES_FIXTURE_WORK_CENTER,
        'shift_code' => 'DAY', 'starts_at' => '2026-12-22 05:00:00', 'ends_at' => '2026-12-22 13:00:00', 'timezone' => 'Europe/Rome', 'non_production' => json_encode([]),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('mfg_mes_sync_changes')->insert(['tenant_id' => MES_FIXTURE_TENANT, 'resource' => 'shift-calendars', 'entity_id' => '018f0000-0000-7000-8000-000000000201', 'operation' => 'upsert', 'changed_at' => now()]);

    $page = mesSendFixture($this, ['method' => 'GET', 'path' => '/api/v1/manufacturing/mes-sync/shift-calendars', 'body' => '', 'tenant_id' => MES_FIXTURE_TENANT, 'signature' => MesHmac::header('k1', 'contract-test-secret', '', 1798000000)])
        ->assertOk()->json();

    expect($page['data'][0]['payload'])->toBe($expected);
});

test('the work-order payload of the pull feed has the keys of its fixture', function (): void {
    $fixture = mesFixture('laravel-to-mes/feed.work-orders.json');

    $page = mesSendFixture($this, ['method' => 'GET', 'path' => '/api/v1/manufacturing/mes-sync/work-orders', 'body' => '', 'tenant_id' => MES_FIXTURE_TENANT, 'signature' => MesHmac::header('k1', 'contract-test-secret', '', 1798000000)])
        ->assertOk()->json();

    expect(mesShape($page['data'][0]['payload']))->toBe(mesShape($fixture['response']['data'][0]['payload']));
});

test('releasing a planned order pushes its operations to the MES Core once the change is committed', function (): void {
    config(['manufacturing_mes.core' => ['base_url' => 'https://mes.test', 'key_id' => 'k1', 'secret' => 'contract-test-secret', 'timeout_seconds' => 5, 'push_tries' => 3]]);
    $planned = MfgProductionOrder::factory()->create(['tenant_id' => MES_FIXTURE_TENANT, 'status' => 'planned', 'target_quantity' => '10']);
    MfgProductionOrderOperation::factory()->create([
        'tenant_id' => MES_FIXTURE_TENANT, 'production_order_id' => $planned->id, 'work_center_id' => MES_FIXTURE_WORK_CENTER, 'machine_center_id' => MES_FIXTURE_MACHINE,
    ]);
    Http::fake(['https://mes.test/*' => Http::response('', 202)]);

    $planned->update(['status' => 'released']);

    Http::assertSent(function (HttpRequest $request) use ($planned): bool {
        $sent = json_decode($request->body(), true);

        return $sent['resource'] === 'work-orders' && $sent['id'] === (string) $planned->id && $sent['deleted'] === false && $sent['payload'] === null;
    });
});
