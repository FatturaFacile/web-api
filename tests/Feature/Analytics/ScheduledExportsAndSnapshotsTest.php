<?php

declare(strict_types=1);

use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Analytics\Mail\ScheduledReportMail;
use Modules\Analytics\Models\DesignedReport;
use Modules\Analytics\Models\ExportRun;
use Modules\Analytics\Models\ExportSchedule;
use Modules\Analytics\Models\KpiSnapshot;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;

require_once __DIR__.'/BiTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2027-03-15 10:00:00');
    Mail::fake();
    Storage::fake((string) config('filesystems.private_disk', config('filesystems.default', 'local')));
    $this->tenant = biTenant('Alfa');
    $this->user = biActAs($this->tenant);
    biInvoice($this->tenant, biCustomer($this->tenant, 'Rossi'), '2027-03-05', '150.00');
    $this->report = DesignedReport::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Weekly sales', 'dataset' => 'sales', 'spec' => biSpec(['customer'], ['net_amount'])]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function biSchedule(array $overrides = []): ExportSchedule
{
    return ExportSchedule::factory()->create([
        'tenant_id' => test()->tenant->id,
        'user_id' => test()->user->id,
        'designed_report_id' => test()->report->id,
        'recipients' => [test()->user->email],
        'next_run_on' => '2027-03-16',
        ...$overrides,
    ]);
}

test('schedules validate their timing and recipients and calculate the first run', function (): void {
    $colleague = biUser($this->tenant, 'accountant');
    $payload = ['designed_report_id' => $this->report->id, 'frequency' => 'daily', 'delivery' => 'email', 'recipients' => [$colleague->email]];

    $this->postJson('/api/v1/analytics/export-schedules', $payload)->assertCreated()->assertJsonPath('data.next_run_on', '2027-03-16')->assertJsonPath('data.user_id', (string) $this->user->id);
    // 2027-03-15 is a Monday: the first Monday from tomorrow is the 22nd; the 10th of a month already past is April's.
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'frequency' => 'weekly', 'day_of_week' => 1])->assertCreated()->assertJsonPath('data.next_run_on', '2027-03-22');
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'frequency' => 'weekly', 'day_of_week' => 2])->assertCreated()->assertJsonPath('data.next_run_on', '2027-03-16');
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'frequency' => 'monthly', 'day_of_month' => 10])->assertCreated()->assertJsonPath('data.next_run_on', '2027-04-10');
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'frequency' => 'monthly', 'day_of_month' => 20])->assertCreated()->assertJsonPath('data.next_run_on', '2027-03-20');

    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'frequency' => 'weekly'])->assertUnprocessable()->assertJsonValidationErrors(['day_of_week']);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'frequency' => 'monthly', 'day_of_month' => 31])->assertUnprocessable()->assertJsonValidationErrors(['day_of_month']);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'frequency' => 'hourly'])->assertUnprocessable()->assertJsonValidationErrors(['frequency']);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'recipients' => []])->assertUnprocessable()->assertJsonValidationErrors(['recipients']);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'recipients' => ['stranger@example.org']])->assertUnprocessable()->assertJsonValidationErrors(['recipients.0']);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'recipients' => ['not-an-email']])->assertUnprocessable()->assertJsonValidationErrors(['recipients.0']);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'designed_report_id' => (string) Str::uuid()])->assertUnprocessable();
    $this->postJson('/api/v1/analytics/export-schedules', ['designed_report_id' => $this->report->id, 'frequency' => 'daily', 'delivery' => 'documents'])->assertCreated();

    config(['analytics.reporting.allow_external_recipients' => true]);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'recipients' => ['Commercialista@Example.org']])->assertCreated()->assertJsonPath('data.recipients.0', 'commercialista@example.org');
    config(['analytics.reporting.allow_external_recipients' => false]);

    // A private report of somebody else cannot be scheduled; one on a dataset the caller cannot read neither.
    $private = DesignedReport::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $colleague->id, 'dataset' => 'sales', 'spec' => biSpec([], ['net_amount'])]);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'designed_report_id' => $private->id])->assertUnprocessable();
    $hr = DesignedReport::factory()->shared()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'dataset' => 'hr_headcount', 'spec' => biSpec([], ['active_headcount'])]);
    Sanctum::actingAs($colleague);
    $this->postJson('/api/v1/analytics/export-schedules', [...$payload, 'designed_report_id' => $hr->id])->assertForbidden();
});

test('schedules are managed by their owner or an administrator only', function (): void {
    $schedule = biSchedule();
    $colleague = biUser($this->tenant, 'accountant');

    Sanctum::actingAs($colleague);
    expect($this->getJson('/api/v1/analytics/export-schedules')->json('data'))->toBe([]);
    $this->getJson("/api/v1/analytics/export-schedules/{$schedule->id}")->assertForbidden();
    $this->deleteJson("/api/v1/analytics/export-schedules/{$schedule->id}")->assertForbidden();

    biActAs($this->tenant, 'admin');
    $this->getJson("/api/v1/analytics/export-schedules/{$schedule->id}")->assertOk();
    $this->putJson("/api/v1/analytics/export-schedules/{$schedule->id}", ['designed_report_id' => $this->report->id, 'frequency' => 'daily', 'delivery' => 'documents', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
    $this->deleteJson("/api/v1/analytics/export-schedules/{$schedule->id}")->assertOk();
    expect(ExportSchedule::query()->count())->toBe(0);

    // Deleting a report (an administrator sees it once it is shared) removes its schedules.
    $this->report->update(['is_shared' => true]);
    $other = biSchedule();
    $this->deleteJson("/api/v1/analytics/designed-reports/{$this->report->id}")->assertOk();
    expect(ExportSchedule::query()->whereKey($other->id)->exists())->toBeFalse();
});

test('the run command stores the file, mails a signed link, audits, and does not repeat on the same day', function (): void {
    $schedule = biSchedule();
    Carbon::setTestNow('2027-03-16 06:00:00');

    expect(Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]))->toBe(0);

    $run = ExportRun::query()->sole();
    expect($run->row_count)->toBe(1)->and($run->schedule_id)->toBe($schedule->id)->and($run->path)->not->toBeNull()->and($run->truncated)->toBeFalse();
    Storage::disk((string) $run->disk)->assertExists((string) $run->path);

    Mail::assertSent(ScheduledReportMail::class, 1);
    $link = null;
    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) use (&$link): bool {
        $link = $mail->link;

        return $mail->hasTo($this->user->email) && $mail->rows === 1;
    });

    $schedule->refresh();
    expect($schedule->last_status)->toBe('ok')->and($schedule->last_error)->toBeNull()->and($schedule->next_run_on->toDateString())->toBe('2027-03-17');

    // The link is the credential: no token, no tenant header.
    app('auth')->forgetGuards();
    $download = $this->withHeaders(['X-Tenant-Id' => ''])->get($link)->assertOk();
    expect($download->streamedContent())->toContain('"customer","net_amount"')->toContain('"Rossi","150.00"');
    $this->get(str_replace('signature=', 'signature=0', $link))->assertForbidden();
    $this->get(URL::route('analytics.exports.download', ['run' => $run->id]))->assertForbidden();

    expect(AuditLedgerEntry::query()->where('action', 'ANALYTICS_SCHEDULED_EXPORT_GENERATED')->count())->toBe(1)
        ->and(AuditLedgerEntry::query()->where('action', 'ANALYTICS_EXPORT_DOWNLOADED')->count())->toBe(1);

    Sanctum::actingAs($this->user);
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);
    expect(ExportRun::query()->count())->toBe(1);
    Mail::assertSent(ScheduledReportMail::class, 1);

    // The next day it runs again; a stopped schedule does not.
    Carbon::setTestNow('2027-03-17 06:00:00');
    $schedule->update(['is_active' => false]);
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);
    expect(ExportRun::query()->count())->toBe(1);
    $schedule->update(['is_active' => true]);
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);
    expect(ExportRun::query()->count())->toBe(2);
});

test('an excel csv schedule delivers to the documents module as well, with the owner as author', function (): void {
    biSchedule(['delivery' => 'both', 'format' => 'excel_csv']);
    Carbon::setTestNow('2027-03-16 06:00:00');

    expect(Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]))->toBe(0);

    $run = ExportRun::query()->sole();
    $document = DmsDocument::query()->where('document_type', 'analytics_export')->sole();
    expect($run->document_id)->toBe($document->id)
        ->and($document->title)->toContain('Weekly sales')
        ->and((string) $document->created_by)->toBe((string) $this->user->id)
        ->and(DmsDocumentVersion::query()->where('document_id', $document->id)->count())->toBe(1);
    Storage::disk((string) $run->disk)->assertExists((string) $run->path);
    expect(Storage::disk((string) $run->disk)->get((string) $run->path))->toStartWith("\xEF\xBB\xBF\"customer\";\"net_amount\"");
    Mail::assertSent(ScheduledReportMail::class, 1);

    biSchedule(['delivery' => 'documents']);
    Carbon::setTestNow('2027-03-17 06:00:00');
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);
    Mail::assertSent(ScheduledReportMail::class, 2);
    expect(DmsDocument::query()->where('document_type', 'analytics_export')->count())->toBe(3);
});

test('a schedule never exports more than its owner may read today', function (): void {
    $hr = DesignedReport::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'dataset' => 'hr_headcount', 'spec' => biSpec(['department'], ['active_headcount'])]);
    $schedule = biSchedule(['designed_report_id' => $hr->id]);
    $this->user->tenants()->updateExistingPivot($this->tenant->id, ['role' => 'accountant']);
    Carbon::setTestNow('2027-03-16 06:00:00');

    expect(Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]))->toBe(1);

    $schedule->refresh();
    expect($schedule->last_status)->toBe('failed')->and($schedule->last_error)->toContain('cannot read')->and($schedule->next_run_on->toDateString())->toBe('2027-03-17')
        ->and(ExportRun::query()->count())->toBe(0);
    Mail::assertNothingSent();

    // A user who left the company, or a report that was deleted, fails too.
    $left = biUser($this->tenant, 'accountant');
    $other = biSchedule(['user_id' => $left->id, 'next_run_on' => '2027-03-16']);
    $left->tenants()->detach($this->tenant->id);
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);
    expect($other->refresh()->last_error)->toContain('no longer a member');
    Mail::assertNothingSent();
});

test('expired export files are removed and the schedule keeps running', function (): void {
    $schedule = biSchedule();
    Carbon::setTestNow('2027-03-16 06:00:00');
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);
    $run = ExportRun::query()->sole();
    $path = (string) $run->path;

    Carbon::setTestNow('2027-03-30 06:00:00');
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);

    expect($run->refresh()->path)->toBeNull()->and($run->file_name)->not->toBeNull();
    Storage::disk((string) $run->disk)->assertMissing($path);
    expect($schedule->refresh()->last_status)->toBe('ok');
});

test('the tables of the module are tenant scoped: another company sees none of the reports, schedules or runs', function (): void {
    biSchedule();
    Carbon::setTestNow('2027-03-16 06:00:00');
    Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]);
    Artisan::call('analytics:refresh-kpi-snapshots', ['--tenant' => [$this->tenant->id]]);
    expect(ExportRun::query()->count())->toBe(1)->and(KpiSnapshot::query()->count())->toBeGreaterThan(0);

    $other = biTenant('Beta');
    biActAs($other);
    $schedule = ExportSchedule::query()->withoutGlobalScopes()->sole();

    expect(DesignedReport::query()->count())->toBe(0)
        ->and(ExportSchedule::query()->count())->toBe(0)
        ->and(ExportRun::query()->count())->toBe(0)
        ->and(KpiSnapshot::query()->count())->toBe(0);
    $this->getJson('/api/v1/analytics/export-schedules')->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/analytics/export-schedules/{$schedule->id}")->assertNotFound();
    $this->getJson("/api/v1/analytics/designed-reports/{$this->report->id}")->assertNotFound();
    expect($this->getJson('/api/v1/analytics/kpi-snapshots?kpi=sales&from=2027-03-01&to=2027-03-31')->assertOk()->json('data'))->toBe([]);
    $this->postJson('/api/v1/analytics/export-schedules', ['designed_report_id' => $this->report->id, 'frequency' => 'daily', 'delivery' => 'documents'])->assertUnprocessable();
});

test('kpi snapshots are materialised per day, idempotent, equal to the live figure and tenant scoped', function (): void {
    biInvoice($this->tenant, biCustomer($this->tenant, 'Verdi'), '2027-03-20', '999.00');
    $other = biTenant('Beta');
    biInvoice($other, biCustomer($other, 'Straniero'), '2027-03-06', '5000.00');

    expect(Artisan::call('analytics:refresh-kpi-snapshots'))->toBe(0);
    $sales = $this->getJson('/api/v1/analytics/kpi-snapshots?kpi=sales&from=2027-03-01&to=2027-03-31')->assertOk()->json('data');
    expect($sales)->toHaveCount(1)
        ->and($sales[0]['snapshot_date'])->toBe('2027-03-14')
        ->and($sales[0]['period_from'])->toBe('2027-03-01')
        ->and($sales[0]['period_to'])->toBe('2027-03-14')
        ->and($sales[0]['value'])->toBe('150.00');
    expect($this->getJson('/api/v1/analytics/kpis/sales?from=2027-03-01&to=2027-03-14')->json('data.value'))->toBe($sales[0]['value']);

    $all = $this->getJson('/api/v1/analytics/kpi-snapshots?from=2027-03-01&to=2027-03-31')->assertOk()->json('data');
    expect(collect($all)->pluck('kpi')->all())->toEqualCanonicalizing(['sales', 'purchases', 'margin', 'receivables-aging', 'payables-aging', 'stock-value']);

    biInvoice($this->tenant, biCustomer($this->tenant, 'Neri'), '2027-03-10', '50.00');
    Artisan::call('analytics:refresh-kpi-snapshots', ['--tenant' => [$this->tenant->id], '--date' => '2027-03-14']);
    $again = $this->getJson('/api/v1/analytics/kpi-snapshots?kpi=sales&from=2027-03-01&to=2027-03-31')->json('data');
    expect($again)->toHaveCount(1)->and($again[0]['value'])->toBe('200.00');

    Artisan::call('analytics:refresh-kpi-snapshots', ['--tenant' => [$this->tenant->id], '--date' => '2027-03-20']);
    expect($this->getJson('/api/v1/analytics/kpi-snapshots?kpi=sales&from=2027-03-01&to=2027-03-31')->json('data.1.value'))->toBe('1199.00');

    // The other company has its own figure.
    biActAs($other);
    expect($this->getJson('/api/v1/analytics/kpi-snapshots?kpi=sales&from=2027-03-01&to=2027-03-31')->json('data.0.value'))->toBe('5000.00');

    $this->getJson('/api/v1/analytics/kpi-snapshots?kpi=hr-headcount')->assertUnprocessable();
    $this->getJson('/api/v1/analytics/kpi-snapshots?kpi=sales&from=2025-01-01&to=2027-03-01')->assertUnprocessable();
    expect(Artisan::call('analytics:refresh-kpi-snapshots', ['--date' => '15/03/2027']))->toBe(1);
});

test('the scheduler runs the two commands every night', function (): void {
    Artisan::call('schedule:list');
    $output = Artisan::output();

    expect($output)->toContain('analytics:refresh-kpi-snapshots')->toContain('analytics:run-scheduled-exports');
});

test('a schedule can deliver an xlsx workbook by link and to the documents module', function (): void {
    biSchedule(['delivery' => 'both', 'format' => 'xlsx']);
    $this->postJson('/api/v1/analytics/export-schedules', ['designed_report_id' => $this->report->id, 'frequency' => 'daily', 'delivery' => 'documents', 'format' => 'xlsx'])->assertCreated()->assertJsonPath('data.format', 'xlsx');
    Carbon::setTestNow('2027-03-16 06:00:00');

    expect(Artisan::call('analytics:run-scheduled-exports', ['--tenant' => [$this->tenant->id]]))->toBe(0);

    $run = ExportRun::query()->where('schedule_id', ExportSchedule::query()->where('delivery', 'both')->value('id'))->sole();
    expect($run->format)->toBe('xlsx')->and($run->path)->toEndWith('.xlsx')->and($run->file_name)->toEndWith('.xlsx');
    $sheets = biReadXlsx(Storage::disk((string) $run->disk)->get((string) $run->path));
    expect(array_values($sheets)[0][1])->toEqual(['Rossi', 150]);

    $link = null;
    Mail::assertSent(ScheduledReportMail::class, function (ScheduledReportMail $mail) use (&$link): bool {
        $link = $mail->link;

        return true;
    });
    app('auth')->forgetGuards();
    $download = $this->get($link)->assertOk();
    expect($download->headers->get('Content-Type'))->toContain('spreadsheetml.sheet');

    Sanctum::actingAs($this->user);
    $version = DmsDocumentVersion::query()->where('document_id', $run->document_id)->sole();
    expect($version->mime_type)->toContain('spreadsheetml')->and(AuditLedgerEntry::query()->where('action', 'ANALYTICS_SCHEDULED_EXPORT_GENERATED')->first()->payload_after['format'])->toBe('xlsx');
});
