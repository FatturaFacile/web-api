<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Integrations\Webhooks\WebhookSigner;
use Modules\Marketplace\Contracts\Connector;
use Modules\Marketplace\Exceptions\ConnectorException;
use Modules\Marketplace\Models\MarketplaceHealthCheck;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Models\MarketplaceSyncRun;
use Modules\Marketplace\Support\ConfigField;
use Modules\Marketplace\Support\ConnectorContext;
use Modules\Marketplace\Support\ConnectorDefinition;
use Modules\Marketplace\Support\ConnectorRegistry;
use Modules\Marketplace\Support\HealthCheckResult;
use Modules\Marketplace\Support\SyncResult;

require_once __DIR__.'/../Analytics/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    $this->tenant = biTenant('Alfa');
    $this->admin = biActAs($this->tenant, 'owner');
    $this->secret = Str::random(40);
    $this->http = new ArrayObject(['handler' => fn () => Http::response([], 200)]);
    Http::fake(fn (Request $request) => ($this->http['handler'])($request));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function mkpActAs(User $user, Tenant $tenant): void
{
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

/**
 * What the external systems answer from now on in the test.
 *
 * @param  array<string, mixed>  $body
 */
function mkpRespond(int $status, array $body = []): void
{
    test()->http['handler'] = fn () => Http::response($body, $status);
}

/**
 * Makes the external systems answer through a closure (to throw a connection error, for example).
 */
function mkpRespondUsing(Closure $handler): void
{
    test()->http['handler'] = $handler;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function mkpInstall(array $overrides = []): TestResponse
{
    return test()->postJson('/api/v1/marketplace/installations', [
        'connector_key' => 'stripe', 'name' => 'Stripe main', 'granted_scopes' => ['payment_links.write', 'treasury.write', 'invoicing.read'], ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $config
 * @param  array<string, mixed>  $extra
 */
function mkpConfigure(string $id, array $config, array $extra = []): TestResponse
{
    return test()->putJson("/api/v1/marketplace/installations/{$id}/configuration", ['config' => $config, ...$extra]);
}

/**
 * An enabled Stripe installation whose health check answers 200.
 */
function mkpEnabledStripe(string $secret, array $extra = []): string
{
    mkpRespond(200, ['data' => []]);
    $id = mkpInstall()->assertCreated()->json('data.id');
    mkpConfigure($id, ['secret_key' => $secret], $extra)->assertOk();
    test()->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertOk()->assertJsonPath('data.status', 'enabled');

    return $id;
}

/**
 * A connector plugged in through the contract.
 */
function mkpAcme(string $version, array $scopes, array $fields, ?Closure $sync = null): Connector
{
    return new class($version, $scopes, $fields, $sync) implements Connector
    {
        /**
         * @param  list<string>  $scopes
         * @param  list<ConfigField>  $fields
         */
        public function __construct(private string $version, private array $scopes, private array $fields, private ?Closure $sync) {}

        public function definition(): ConnectorDefinition
        {
            return new ConnectorDefinition('acme-erp', 'Acme ERP', 'Test connector', ConnectorDefinition::Automation, $this->version, $this->fields, $this->scopes, ['items'], 5, 10);
        }

        public function checkHealth(ConnectorContext $context): HealthCheckResult
        {
            return HealthCheckResult::healthy(5);
        }

        public function sync(ConnectorContext $context, string $resource): SyncResult
        {
            return $this->sync === null ? new SyncResult(3, 1, 'done') : ($this->sync)($context, $resource);
        }
    };
}

test('the catalog offers ready connectors of every category and every definition is consistent with the access rules', function (): void {
    $catalog = $this->getJson('/api/v1/marketplace/connectors')->assertOk()->json('data');
    $categories = array_unique(array_column($catalog, 'category'));
    sort($categories);

    expect($categories)->toBe(['accounting_export', 'automation', 'ecommerce', 'payment', 'shipping']);

    $modules = array_keys(config('permissions.modules'));

    foreach ($catalog as $entry) {
        expect($entry['version'])->toMatch('/^\d+\.\d+\.\d+$/')->and($entry['resources'])->not->toBeEmpty();

        foreach ($entry['required_scopes'] as $scope) {
            [$module, $access] = explode('.', $scope);
            expect($modules)->toContain($module)->and(['read', 'write'])->toContain($access);
        }

        foreach ($entry['config_schema'] as $field) {
            expect(['string', 'url', 'secret', 'number', 'boolean', 'select'])->toContain($field['type']);
        }
    }

    expect(array_column($this->getJson('/api/v1/marketplace/connectors?category=payment')->assertOk()->json('data'), 'key'))->toEqualCanonicalizing(['stripe', 'mollie'])
        ->and(array_column($this->getJson('/api/v1/marketplace/connectors?search=shopify')->assertOk()->json('data'), 'key'))->toBe(['shopify']);
    $this->getJson('/api/v1/marketplace/connectors?category=weapons')->assertUnprocessable();
    $this->getJson('/api/v1/marketplace/connectors/stripe')->assertOk()->assertJsonPath('data.config_schema.0.type', 'secret');
    $this->getJson('/api/v1/marketplace/connectors/nothing')->assertNotFound();
});

test('installing grants the required scopes, never invalid ones, and limits the installations of one connector', function (): void {
    mkpInstall()->assertCreated()->assertJsonPath('data.status', 'pending_configuration')->assertJsonPath('data.connector_version', '1.0.0')->assertJsonPath('data.credentials_set', []);

    mkpInstall(['name' => 'Another', 'granted_scopes' => ['invoicing.read']])->assertUnprocessable()->assertJsonValidationErrors('granted_scopes');
    mkpInstall(['name' => 'Another', 'granted_scopes' => ['payment_links.write', 'treasury.write', 'invoicing.read', 'bogus.write']])->assertUnprocessable()->assertJsonValidationErrors('granted_scopes');
    mkpInstall(['name' => 'Another', 'granted_scopes' => ['payment_links.write', 'treasury.write', 'invoicing.read', 'crm.admin']])->assertUnprocessable();
    mkpInstall(['name' => 'Another', 'connector_key' => 'nothing'])->assertUnprocessable()->assertJsonValidationErrors('connector_key');
    mkpInstall()->assertUnprocessable()->assertJsonValidationErrors('name');
    mkpInstall(['name' => 'Wider', 'granted_scopes' => ['payment_links.write', 'treasury.write', 'invoicing.read', 'crm.read']])->assertCreated()->assertJsonCount(4, 'data.granted_scopes');

    foreach (range(3, 5) as $number) {
        mkpInstall(['name' => "Stripe {$number}"])->assertCreated();
    }

    mkpInstall(['name' => 'Stripe 6'])->assertUnprocessable()->assertJsonValidationErrors('connector_key');
    $this->getJson('/api/v1/marketplace/installations?connector_key=stripe')->assertOk()->assertJsonCount(5, 'data');
    $this->getJson('/api/v1/marketplace/installations?status=enabled')->assertOk()->assertJsonCount(0, 'data');
});

test('the configuration follows the schema, keeps secrets write-only and stores them encrypted', function (): void {
    $id = mkpInstall()->assertCreated()->json('data.id');

    mkpConfigure($id, [])->assertUnprocessable()->assertJsonValidationErrors('config.secret_key');
    mkpConfigure($id, ['secret_key' => $this->secret, 'webhook' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('config.webhook');
    mkpConfigure($id, ['secret_key' => 'abc'])->assertUnprocessable()->assertJsonValidationErrors('config.secret_key');
    mkpConfigure($id, ['secret_key' => ['nested']])->assertUnprocessable();

    $response = mkpConfigure($id, ['secret_key' => $this->secret])->assertOk()->assertJsonPath('data.status', 'disabled')->assertJsonPath('data.credentials_set', ['secret_key']);
    expect($response->getContent())->not->toContain($this->secret);
    expect($this->getJson("/api/v1/marketplace/installations/{$id}")->assertOk()->getContent())->not->toContain($this->secret);
    expect($this->getJson('/api/v1/marketplace/installations')->assertOk()->getContent())->not->toContain($this->secret);

    $raw = (string) DB::table('marketplace_installations')->where('id', $id)->value('credentials');
    expect($raw)->not->toContain($this->secret)->and($raw)->not->toBe('')
        ->and(MarketplaceInstallation::query()->find($id)->credentials)->toBe(['secret_key' => $this->secret])
        ->and(MarketplaceInstallation::query()->find($id)->toArray())->not->toHaveKey('credentials')
        ->and((new MarketplaceInstallation)->auditPayload(['credentials' => $raw, 'name' => 'x']))->toBe(['credentials' => '[encrypted]', 'name' => 'x']);

    mkpConfigure($id, [])->assertOk()->assertJsonPath('data.credentials_set', ['secret_key']);
    expect(MarketplaceInstallation::query()->find($id)->credentials)->toBe(['secret_key' => $this->secret]);
    mkpConfigure($id, ['secret_key' => ''])->assertUnprocessable();

    $rotated = Str::random(40);
    mkpConfigure($id, ['secret_key' => $rotated], ['rate_limit_per_minute' => 50])->assertOk()->assertJsonPath('data.rate_limit_per_minute', 50)->assertJsonPath('data.effective_rate_limit_per_minute', 50);
    expect(MarketplaceInstallation::query()->find($id)->credentials)->toBe(['secret_key' => $rotated]);
    mkpConfigure($id, [], ['rate_limit_per_minute' => 5000])->assertUnprocessable()->assertJsonValidationErrors('rate_limit_per_minute');
    mkpConfigure($id, [], ['rate_limit_per_minute' => null])->assertOk()->assertJsonPath('data.effective_rate_limit_per_minute', 100);
});

test('address fields accept only public https addresses', function (): void {
    $id = mkpInstall(['connector_key' => 'woocommerce', 'name' => 'Shop', 'granted_scopes' => ['invoicing.write', 'inventory.write', 'crm.write']])->assertCreated()->json('data.id');
    $keys = ['consumer_key' => Str::random(24), 'consumer_secret' => Str::random(24)];

    foreach (['http://shop.example.com', 'https://localhost', 'https://10.0.0.5', 'https://169.254.169.254', 'ftp://shop.example.com', 'not a url', 'https://user:pass@shop.example.com'] as $bad) {
        mkpConfigure($id, [...$keys, 'store_url' => $bad])->assertUnprocessable()->assertJsonValidationErrors('config.store_url');
    }

    mkpConfigure($id, [...$keys, 'store_url' => 'https://shop.example.com'])->assertOk()->assertJsonPath('data.settings.store_url', 'https://shop.example.com');
});

test('enabling needs a configuration, the required scopes and a passing health check', function (): void {
    mkpRespond(401, ['error' => 'bad key']);
    $id = mkpInstall()->assertCreated()->json('data.id');

    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertUnprocessable()->assertJsonValidationErrors('status');
    $this->postJson("/api/v1/marketplace/installations/{$id}/health-checks")->assertUnprocessable();

    mkpConfigure($id, ['secret_key' => $this->secret])->assertOk();
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertUnprocessable()->assertJsonValidationErrors('status');
    expect(MarketplaceInstallation::query()->find($id)->status)->toBe('disabled')
        ->and(MarketplaceInstallation::query()->find($id)->last_health_status)->toBe('unhealthy');

    mkpRespond(200, ['available' => []]);
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertOk()->assertJsonPath('data.status', 'enabled')->assertJsonPath('data.health.status', 'healthy');
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer '.$this->secret) && str_ends_with($request->url(), '/v1/balance'));

    mkpConfigure($id, [])->assertOk()->assertJsonPath('data.status', 'enabled')->assertJsonPath('data.health.status', 'unknown');
    $this->postJson("/api/v1/marketplace/installations/{$id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');
    $this->postJson("/api/v1/marketplace/installations/{$id}/disable")->assertOk()->assertJsonPath('data.status', 'disabled');

    MarketplaceInstallation::query()->whereKey($id)->update(['granted_scopes' => ['invoicing.read']]);
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('health checks classify the answers of the external system and keep a bounded history', function (): void {
    $id = mkpInstall()->assertCreated()->json('data.id');
    mkpConfigure($id, ['secret_key' => $this->secret])->assertOk();

    $cases = [
        [fn () => Http::response([], 200), 'healthy'],
        [fn () => Http::response([], 401), 'unhealthy'],
        [fn () => Http::response([], 429), 'degraded'],
        [fn () => Http::response([], 503), 'unhealthy'],
        [fn () => Http::response([], 404), 'degraded'],
        [fn () => throw new ConnectionException('boom '.$this->secret), 'unhealthy'],
    ];

    foreach ($cases as [$answer, $expected]) {
        mkpRespondUsing($answer);
        $check = $this->postJson("/api/v1/marketplace/installations/{$id}/health-checks")->assertCreated()->json('data');
        expect($check['status'])->toBe($expected)->and((string) $check['message'])->not->toContain($this->secret);
    }

    expect(MarketplaceInstallation::query()->find($id)->last_health_status)->toBe('unhealthy');
    $this->getJson("/api/v1/marketplace/installations/{$id}/health-checks")->assertOk()->assertJsonCount(6, 'data')->assertJsonPath('data.0.status', 'unhealthy');

    config(['marketplace.health_checks_keep' => 3]);
    mkpRespond(200, []);
    $this->postJson("/api/v1/marketplace/installations/{$id}/health-checks")->assertCreated();
    expect(MarketplaceHealthCheck::query()->where('installation_id', $id)->count())->toBe(3);
});

test('a sync pulls the resource with the stored credentials and logs the run', function (): void {
    $id = mkpEnabledStripe($this->secret);
    mkpRespond(200, ['data' => [['id' => 'ch_1'], ['id' => 'ch_2'], ['id' => 'ch_3']]]);

    $run = $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertCreated()->json('data');

    expect($run['status'])->toBe('succeeded')->and($run['records_processed'])->toBe(3)->and($run['trigger'])->toBe('manual')->and($run['finished_at'])->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v1/charges') && $request->hasHeader('Authorization', 'Bearer '.$this->secret));
    expect(MarketplaceInstallation::query()->find($id)->last_sync_at)->not->toBeNull();

    $this->getJson("/api/v1/marketplace/installations/{$id}/sync-runs")->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/marketplace/sync-runs?status=succeeded')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.installation_name', 'Stripe main');
    $this->getJson('/api/v1/marketplace/sync-runs?status=failed')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/marketplace/sync-runs?status=nonsense')->assertUnprocessable();

    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'galaxies'])->assertUnprocessable()->assertJsonValidationErrors('resource');
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", [])->assertUnprocessable();

    $this->postJson("/api/v1/marketplace/installations/{$id}/disable")->assertOk();
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

test('a failed sync is logged without any credential', function (): void {
    $id = mkpEnabledStripe($this->secret);
    mkpRespond(500, ['error' => 'Invalid key '.$this->secret]);

    $run = $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'payouts'])->assertCreated()->json('data');
    expect($run['status'])->toBe('failed')->and($run['message'])->not->toContain($this->secret)->and($run['message'])->toContain('500');

    mkpRespondUsing(fn () => throw new ConnectionException('Cannot reach host with key '.$this->secret));
    $second = $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'payouts'])->assertCreated()->json('data');
    expect($second['status'])->toBe('failed')->and(json_encode($second))->not->toContain($this->secret);

    mkpRespond(401, []);
    $third = $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'customers'])->assertCreated()->json('data');
    expect($third['status'])->toBe('failed')->and($third['message'])->toContain('credentials');
    expect(MarketplaceInstallation::query()->find($id)->last_sync_at)->toBeNull();
});

test('a connector that leaks a credential in its error has it redacted before the log', function (): void {
    app(ConnectorRegistry::class)->register(mkpAcme('1.0.0', ['reporting.read'], [new ConfigField('api_token', 'API token', 'secret')], function (ConnectorContext $context): SyncResult {
        throw new ConnectorException('Upstream refused token '.$context->credential('api_token').' for the account');
    }));

    $id = mkpInstall(['connector_key' => 'acme-erp', 'name' => 'Acme', 'granted_scopes' => ['reporting.read']])->assertCreated()->json('data.id');
    mkpConfigure($id, ['api_token' => $this->secret])->assertOk();
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertOk();

    $run = $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'items'])->assertCreated()->json('data');
    expect($run['status'])->toBe('failed')->and($run['message'])->toBe('Upstream refused token *** for the account');
    expect((string) MarketplaceSyncRun::query()->find($run['id'])->message)->not->toContain($this->secret);
});

test('the rate limit of an installation refuses the calls beyond it without reaching the external system', function (): void {
    $id = mkpEnabledStripe($this->secret, ['rate_limit_per_minute' => 2]);
    mkpRespond(200, ['data' => []]);

    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertCreated();
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertCreated();
    $blocked = $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges']);

    $blocked->assertStatus(429)->assertHeader('Retry-After');
    expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
    Http::assertSentCount(3);
    expect(MarketplaceSyncRun::query()->where('status', 'rate_limited')->count())->toBe(1);
    $this->getJson('/api/v1/marketplace/overview')->assertOk()->assertJsonPath('data.recent_problem_runs.0.status', 'rate_limited');

    $this->travel(2)->minutes();
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertCreated();
});

test('one run of a resource at a time, and a run that never finished stops blocking', function (): void {
    $id = mkpEnabledStripe($this->secret);
    mkpRespond(200, ['data' => []]);
    $installation = MarketplaceInstallation::query()->findOrFail($id);
    MarketplaceSyncRun::factory()->create(['tenant_id' => $this->tenant->id, 'installation_id' => $installation->id, 'resource' => 'charges', 'status' => 'running', 'finished_at' => null, 'started_at' => now()->subMinutes(2)]);

    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertUnprocessable()->assertJsonValidationErrors('resource');
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'payouts'])->assertCreated();

    MarketplaceSyncRun::query()->where('status', 'running')->update(['started_at' => now()->subMinutes(30)]);
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertCreated();
    expect(MarketplaceSyncRun::query()->where('status', 'failed')->count())->toBe(1);
});

test('uninstalling wipes the credentials, keeps the history and waits for a run in progress', function (): void {
    $id = mkpEnabledStripe($this->secret);
    mkpRespond(200, ['data' => [[]]]);
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertCreated();
    $running = MarketplaceSyncRun::factory()->create(['tenant_id' => $this->tenant->id, 'installation_id' => $id, 'resource' => 'payouts', 'status' => 'running', 'finished_at' => null, 'started_at' => now()]);

    $this->deleteJson("/api/v1/marketplace/installations/{$id}")->assertUnprocessable();
    expect(MarketplaceInstallation::query()->find($id))->not->toBeNull();

    $running->update(['status' => 'succeeded', 'finished_at' => now()]);
    $this->deleteJson("/api/v1/marketplace/installations/{$id}")->assertNoContent();
    $this->getJson("/api/v1/marketplace/installations/{$id}")->assertNotFound();

    $row = DB::table('marketplace_installations')->where('id', $id)->first();
    expect($row->credentials)->toBeNull()->and($row->settings)->toBeNull()->and($row->deleted_at)->not->toBeNull()
        ->and(MarketplaceSyncRun::query()->where('installation_id', $id)->count())->toBe(2);
});

test('a newer version of a connector needs its new scopes and a complete configuration', function (): void {
    $registry = new ConnectorRegistry;
    $registry->register(mkpAcme('1.0.0', ['reporting.read'], [new ConfigField('api_token', 'API token', 'secret')]));
    app()->instance(ConnectorRegistry::class, $registry);

    $id = mkpInstall(['connector_key' => 'acme-erp', 'name' => 'Acme', 'granted_scopes' => ['reporting.read']])->assertCreated()->json('data.id');
    mkpConfigure($id, ['api_token' => $this->secret])->assertOk();
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertOk();
    $newer = new ConnectorRegistry;
    $newer->register(mkpAcme('1.1.0', ['reporting.read', 'invoicing.write'], [new ConfigField('api_token', 'API token', 'secret'), new ConfigField('region', 'Region', 'select', options: ['eu', 'us'])]));
    app()->instance(ConnectorRegistry::class, $newer);

    $this->getJson("/api/v1/marketplace/installations/{$id}")->assertOk()->assertJsonPath('data.update_available', true)->assertJsonPath('data.latest_version', '1.1.0');
    $this->getJson('/api/v1/marketplace/overview')->assertOk()->assertJsonCount(1, 'data.updates_available');
    $this->postJson("/api/v1/marketplace/installations/{$id}/upgrade")->assertUnprocessable()->assertJsonValidationErrors('granted_scopes');
    $this->postJson("/api/v1/marketplace/installations/{$id}/upgrade", ['granted_scopes' => ['invoicing.write', 'nonsense.write']])->assertUnprocessable();

    $this->postJson("/api/v1/marketplace/installations/{$id}/upgrade", ['granted_scopes' => ['invoicing.write']])->assertOk()
        ->assertJsonPath('data.connector_version', '1.1.0')->assertJsonPath('data.status', 'pending_configuration')->assertJsonPath('data.update_available', false);
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertUnprocessable();

    mkpConfigure($id, ['region' => 'eu'])->assertOk()->assertJsonPath('data.status', 'disabled');
});

test('an installation that already runs the latest version cannot be upgraded', function (): void {
    $id = mkpInstall()->assertCreated()->json('data.id');

    $this->getJson("/api/v1/marketplace/installations/{$id}")->assertOk()->assertJsonPath('data.update_available', false);
    $this->postJson("/api/v1/marketplace/installations/{$id}/upgrade")->assertUnprocessable()->assertJsonValidationErrors('connector_version');
});

test('a connector plugged in through the contract is installable and its sync runs through the same log', function (): void {
    app(ConnectorRegistry::class)->register(mkpAcme('1.0.0', ['reporting.read'], [new ConfigField('region', 'Region', 'select', options: ['eu', 'us'], default: 'eu')]));
    expect(fn () => app(ConnectorRegistry::class)->register(mkpAcme('1.0.0', [], [])))->toThrow(InvalidArgumentException::class);

    $id = mkpInstall(['connector_key' => 'acme-erp', 'name' => 'Acme', 'granted_scopes' => ['reporting.read']])->assertCreated()->json('data.id');
    mkpConfigure($id, ['region' => 'mars'])->assertUnprocessable()->assertJsonValidationErrors('config.region');
    mkpConfigure($id, [])->assertOk()->assertJsonPath('data.settings.region', 'eu');
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertOk();

    $run = $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'items'])->assertCreated()->json('data');
    expect($run['status'])->toBe('succeeded')->and($run['records_processed'])->toBe(3)->and($run['records_failed'])->toBe(1)->and($run['message'])->toBe('done');
});

test('the webhook connector posts events signed like the webhooks of the integrations module', function (): void {
    mkpRespond(200, ['ok' => true]);
    $id = mkpInstall(['connector_key' => 'generic-webhook', 'name' => 'Hook', 'granted_scopes' => ['reporting.read']])->assertCreated()->json('data.id');

    mkpConfigure($id, ['endpoint_url' => 'https://hooks.example.com/in', 'signing_secret' => $this->secret])->assertOk();
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertOk();
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'events'])->assertCreated()->assertJsonPath('data.status', 'succeeded');

    Http::assertSent(function (Request $request): bool {
        $header = $request->header(WebhookSigner::SignatureHeader)[0] ?? '';

        return $request->url() === 'https://hooks.example.com/in' && WebhookSigner::verify($header, $request->body(), $this->secret);
    });

    mkpRespond(500, []);
    $this->postJson("/api/v1/marketplace/installations/{$id}/health-checks")->assertCreated()->assertJsonPath('data.status', 'unhealthy');
});

test('only owners and administrators use the marketplace and nothing crosses the company boundary', function (): void {
    $id = mkpInstall()->assertCreated()->json('data.id');
    mkpConfigure($id, ['secret_key' => $this->secret])->assertOk();

    foreach (['member', 'accountant', 'accountant_readonly', 'sales'] as $role) {
        mkpActAs(biUser($this->tenant, $role), $this->tenant);
        $this->getJson('/api/v1/marketplace/connectors')->assertForbidden();
        $this->getJson('/api/v1/marketplace/installations')->assertForbidden();
        $this->getJson("/api/v1/marketplace/installations/{$id}")->assertForbidden();
        $this->postJson('/api/v1/marketplace/installations', ['connector_key' => 'stripe', 'name' => 'x', 'granted_scopes' => ['invoicing.read']])->assertForbidden();
        $this->deleteJson("/api/v1/marketplace/installations/{$id}")->assertForbidden();
    }

    $other = biTenant('Beta');
    mkpActAs(biUser($other, 'admin'), $other);
    $this->getJson('/api/v1/marketplace/installations')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/marketplace/installations/{$id}")->assertNotFound();
    $this->putJson("/api/v1/marketplace/installations/{$id}/configuration", ['config' => ['secret_key' => Str::random(30)]])->assertNotFound();
    $this->postJson("/api/v1/marketplace/installations/{$id}/enable")->assertNotFound();
    $this->postJson("/api/v1/marketplace/installations/{$id}/health-checks")->assertNotFound();
    $this->postJson("/api/v1/marketplace/installations/{$id}/sync-runs", ['resource' => 'charges'])->assertNotFound();
    $this->getJson("/api/v1/marketplace/installations/{$id}/sync-runs")->assertNotFound();
    $this->deleteJson("/api/v1/marketplace/installations/{$id}")->assertNotFound();
    $this->getJson('/api/v1/marketplace/sync-runs')->assertOk()->assertJsonCount(0, 'data');

    mkpInstall(['name' => 'Stripe main'])->assertCreated();
    expect(MarketplaceInstallation::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('the health command checks every enabled installation of every company and reports the unhealthy ones', function (): void {
    $id = mkpEnabledStripe($this->secret);
    mkpInstall(['name' => 'Idle'])->assertCreated();
    mkpRespond(503, []);

    $this->artisan('marketplace:check-health', ['--tenant' => [$this->tenant->id]])->assertFailed();
    expect(MarketplaceInstallation::query()->find($id)->last_health_status)->toBe('unhealthy')
        ->and(MarketplaceHealthCheck::query()->count())->toBe(2);

    mkpRespond(200, []);
    $this->artisan('marketplace:check-health', ['--tenant' => [$this->tenant->id]])->assertSuccessful();
    expect(MarketplaceInstallation::query()->find($id)->last_health_status)->toBe('healthy');
});
