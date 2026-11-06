<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Queue\TenantJobDispatcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

final class CountContactsProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        Cache::put('probe:'.app(TenantContext::class)->getTenantId(), Contact::count());
    }
}

final class FailingProbeJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 1;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

beforeEach(function (): void {
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);

    foreach ([$this->tenant->id, $this->tenant->id, $this->other->id] as $tenantId) {
        DB::table('contacts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'company_name' => 'C', 'country' => 'CH', 'created_at' => now(), 'updated_at' => now()]);
    }
});

afterEach(fn () => app(TenantContext::class)->clear());

function workQueue(): void
{
    app(TenantContext::class)->clear();
    Artisan::call('queue:work', ['connection' => 'database', '--stop-when-empty' => true, '--tries' => 1, '--memory' => 4096]);
}

test('a queued job finds the data of the tenant that dispatched it', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    CountContactsProbeJob::dispatch()->onConnection('database');

    workQueue();

    expect(Cache::get("probe:{$this->tenant->id}"))->toBe(2)
        ->and(app(TenantContext::class)->isExplicit())->toBeFalse();
});

test('each job of a worker runs for its own tenant', function (): void {
    foreach ([$this->tenant, $this->other] as $tenant) {
        app(TenantContext::class)->setTenant($tenant);
        CountContactsProbeJob::dispatch()->onConnection('database');
    }

    workQueue();

    expect(Cache::get("probe:{$this->tenant->id}"))->toBe(2)
        ->and(Cache::get("probe:{$this->other->id}"))->toBe(1);
});

test('a sync job gives the tenant back to the request', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);

    CountContactsProbeJob::dispatchSync();

    expect(app(TenantContext::class)->getTenantId())->toBe($this->tenant->id);
});

test('a failed job does not leave its tenant behind', function (): void {
    app(TenantContext::class)->setTenant($this->tenant);
    FailingProbeJob::dispatch()->onConnection('database');

    workQueue();

    expect(app(TenantContext::class)->isExplicit())->toBeFalse();
});

test('scheduled jobs start once per active tenant, each in its own context', function (): void {
    Tenant::create(['name' => 'Fermo', 'legal_name' => 'Fermo SA', 'status' => 'suspended']);
    config(['queue.default' => 'database']);

    $count = app(TenantJobDispatcher::class)->dispatchForEachTenant(fn () => new CountContactsProbeJob);

    workQueue();

    expect($count)->toBe(2)
        ->and(Cache::get("probe:{$this->tenant->id}"))->toBe(2)
        ->and(Cache::get("probe:{$this->other->id}"))->toBe(1);
});

test('dispatching with a fake queue still works', function (): void {
    Queue::fake();
    app(TenantContext::class)->setTenant($this->tenant);

    CountContactsProbeJob::dispatch();

    Queue::assertPushed(CountContactsProbeJob::class);
});
