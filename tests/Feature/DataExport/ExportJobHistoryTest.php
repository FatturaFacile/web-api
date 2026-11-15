<?php

declare(strict_types=1);

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\DataExport\Jobs\RunExportJob;
use Modules\DataExport\Models\ExportJob;
use Modules\DataExport\Models\ExportSchedule;
use Modules\DataExport\Services\ExportJobService;

uses(RefreshDatabase::class);

function exportMember(Tenant $tenant, string $role = 'owner'): User
{
    $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => Str::random(24)]);
    $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

    return $user;
}

function exportActAs(User $user, Tenant $tenant): void
{
    app(TenantContext::class)->clear();
    Sanctum::actingAs($user);
    test()->withHeader('X-Tenant-Id', $tenant->id);
}

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    Storage::fake('local');
    $this->tenant = Tenant::create(['name' => 'Alfa', 'legal_name' => 'Alfa SA']);
    $this->user = exportMember($this->tenant);
    exportActAs($this->user, $this->tenant);
});

afterEach(function (): void {
    Carbon::setTestNow();
    app(TenantContext::class)->clear();
});

test('an export is queued and recorded in the history', function (): void {
    Queue::fake();

    $id = $this->postJson('/api/v1/exports/jobs', ['dataset' => 'contacts'])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'queued')
        ->json('data.id');

    Queue::assertPushed(RunExportJob::class, fn (RunExportJob $job): bool => $job->exportJobId === $id);

    $this->getJson('/api/v1/exports/jobs?status=queued')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/exports/jobs?status=failed')->assertOk()->assertJsonCount(0, 'data');
});

test('the queued run stores the file and a signed link downloads it', function (): void {
    Contact::create(['tenant_id' => $this->tenant->id, 'type' => 'customer', 'company_name' => 'Cliente Uno', 'email' => 'uno@example.com']);

    $id = $this->postJson('/api/v1/exports/jobs', ['dataset' => 'contacts'])->assertStatus(202)->json('data.id');
    (new RunExportJob($id, (string) $this->tenant->id))->handle(app(ExportJobService::class));

    $this->getJson("/api/v1/exports/jobs/{$id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.row_count', 1)
        ->assertJsonPath('data.is_downloadable', true);

    $url = $this->getJson("/api/v1/exports/jobs/{$id}/link")->assertOk()->json('data.url');
    app(TenantContext::class)->clear();
    $this->app['auth']->forgetGuards();
    $download = $this->withoutHeader('X-Tenant-Id')->get($url);
    $download->assertOk();
    expect($download->streamedContent())->toContain('Cliente Uno');

    $this->get(preg_replace('/signature=[^&]+/', 'signature=tampered', $url))->assertForbidden();
});

test('a failed export keeps its error and can be retried', function (): void {
    Queue::fake();
    $job = ExportJob::create(['tenant_id' => $this->tenant->id, 'dataset' => 'contacts', 'status' => ExportJob::Failed, 'error' => 'boom']);

    $this->getJson("/api/v1/exports/jobs/{$job->id}/link")->assertStatus(409);
    $this->postJson("/api/v1/exports/jobs/{$job->id}/retry")->assertStatus(202)->assertJsonPath('data.status', 'queued');
    Queue::assertPushed(RunExportJob::class);

    $job->update(['status' => ExportJob::Completed]);
    $this->postJson("/api/v1/exports/jobs/{$job->id}/retry")->assertStatus(409);
});

test('the accountant archive needs a period of at most one year', function (): void {
    Queue::fake();

    $this->postJson('/api/v1/exports/jobs', ['dataset' => 'accountant_archive'])->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    $this->postJson('/api/v1/exports/jobs', ['dataset' => 'accountant_archive', 'from' => '2026-01-01', 'to' => '2027-06-30'])->assertUnprocessable()->assertJsonValidationErrors(['to']);
    $this->postJson('/api/v1/exports/jobs', ['dataset' => 'accountant_archive', 'from' => '2026-01-01', 'to' => '2026-12-31'])->assertStatus(202);
});

test('the history never shows the exports of another company', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta SA']);
    ExportJob::create(['tenant_id' => $other->id, 'dataset' => 'invoices', 'status' => ExportJob::Completed]);

    $this->getJson('/api/v1/exports/jobs')->assertOk()->assertJsonCount(0, 'data');
});

test('a due schedule queues an export and moves to its next day', function (): void {
    Queue::fake();

    $id = $this->postJson('/api/v1/exports/schedules', ['dataset' => 'invoices', 'frequency' => 'monthly', 'period' => 'previous_month'])
        ->assertCreated()
        ->assertJsonPath('data.next_run_on', '2027-03-16')
        ->json('data.id');

    $this->postJson('/api/v1/exports/schedules', ['dataset' => 'accountant_archive', 'frequency' => 'daily', 'period' => 'all'])->assertUnprocessable();

    Carbon::setTestNow('2027-03-16 06:00:00');
    Artisan::call('exports:run-scheduled', ['--tenant' => [(string) $this->tenant->id]]);

    Queue::assertPushed(RunExportJob::class, 1);
    $job = ExportJob::query()->where('schedule_id', $id)->firstOrFail();
    expect($job->period_from->toDateString())->toBe('2027-02-01')
        ->and($job->period_to->toDateString())->toBe('2027-02-28')
        ->and(ExportSchedule::query()->findOrFail($id)->next_run_on->toDateString())->toBe('2027-04-16');

    Artisan::call('exports:run-scheduled', ['--tenant' => [(string) $this->tenant->id]]);
    Queue::assertPushed(RunExportJob::class, 1);

    $this->deleteJson("/api/v1/exports/schedules/{$id}")->assertOk();
    $this->getJson('/api/v1/exports/schedules')->assertOk()->assertJsonCount(0, 'data');
});

test('expired files are removed but the history row stays', function (): void {
    Storage::disk('local')->put('exports/x.csv', 'data');
    $job = ExportJob::create(['tenant_id' => $this->tenant->id, 'dataset' => 'contacts', 'status' => ExportJob::Completed, 'disk' => 'local', 'path' => 'exports/x.csv', 'expires_at' => now()->subDay()]);

    Artisan::call('exports:run-scheduled', ['--tenant' => [(string) $this->tenant->id]]);

    Storage::disk('local')->assertMissing('exports/x.csv');
    expect($job->refresh()->path)->toBeNull();
    $this->getJson("/api/v1/exports/jobs/{$job->id}")->assertJsonPath('data.is_downloadable', false);
});
