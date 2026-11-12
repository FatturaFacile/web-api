<?php

declare(strict_types=1);

use App\Models\Invoice;
use App\Models\Privacy\PrivacyRetentionPolicy;
use App\Models\Privacy\PrivacyRetentionRun;
use App\Models\Tenant;
use App\Services\Privacy\PersonalDataRegistry;
use App\Services\Privacy\PersonalDataRetention;
use App\Services\Privacy\StatutoryRetentionCatalogue;
use App\Services\Tenancy\TenantContext;
use Enterprise\AuditLedger\Models\AuditLedgerEntry;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Documents\Jobs\ApplyDocumentRetentionJob;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsRetentionPolicy;
use Modules\Documents\Services\DocumentRetention;
use Modules\DocumentTrust\Enums\PreservationDocumentClass;
use Modules\HumanResources\Jobs\PurgeExpiredEmployeeDocumentsJob;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeDocument;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeDailyRecord;
use Modules\HumanResources\Services\EmployeeDocumentService;
use Tests\Support\PrivacyFixtures;

uses(RefreshDatabase::class);

/**
 * Every scheduled entry of routes/console.php, classified. An entry missing from both lists fails the test, so a new
 * cleanup is reviewed against the statutory retention classes before it ships.
 */
const SCHEDULE_CLEANUPS = [
    'tenants:backup' => 'backups: rotation only, archives are copies (open question)',
    'auth:prune-unverified-users' => 'unverified accounts, no statutory record',
    'sanctum:prune-expired' => 'expired tokens',
    'model:prune' => 'password reset tokens',
    'telescope:prune' => 'debug entries',
    'webhooks:prune' => 'webhook delivery log, technical data',
    'time:purge-expired' => 'guarded: working time records, 5 years',
    'privacy:apply-retention' => 'guarded: erasable tables only',
    'demo:reset' => 'guarded: demo flag',
    'workflow:prune-tokens' => 'approval tokens, not approval decisions',
    'hr:purge-applications' => 'applicant data, erasable',
    'dms:retention' => 'guarded: statutory document types',
    'hr:purge-employee-documents' => 'guarded: payslips',
    'visitors:purge' => 'visitor data, erasable',
    'associations:expire-memberships' => 'import previews, memberships stay',
    'sales:expire-quotes' => 'status change only',
    'inventory:expire-lots' => 'status change only',
    'inventory:expire-reservations' => 'status change only',
    'contracts:deadlines' => 'status change only',
    'document-trust:verify-preservation' => 'verification only, disposal is manual',
    'Enterprise\EventBus\Jobs\MaintainOutboxLifecycleJob' => 'outbox archive copies (open question)',
];

const SCHEDULE_NON_CLEANUPS = [
    'subscriptions:process', 'general-ledger:run-scheduled', 'analytics:refresh-kpi-snapshots', 'analytics:run-scheduled-exports',
    'webhooks:dispatch-overdue', 'invoices:send-reminders', 'ecommerce:sync-orders', 'sdi:poll', 'Enterprise\EventBus\Jobs\RelayOutboxEventsJob',
    'workflow:webhooks', 'workflow:timeouts', 'crm:activity-reminders', 'esm:sla-breaches', 'esm:support-mailboxes', 'esm:contract-renewals',
    'inventory:reorder-rules', 'inventory:valuation', 'procurement:expiring-contracts', 'procurement:supplier-otif', 'hr:expiring-certifications',
    'eam:expiring-coverage', 'hr:leave-accruals', 'subscriptions:metered-usage', 'manufacturing:mrp', 'manufacturing:forecast',
    'associations:fee-reminders', 'associations:renewal-reminders', 'associations:certificate-alerts', 'associations:volunteer-alerts',
    'customs:assessment-decisions', 'workspace:sync', 'fiscal-retail:retry-transmissions', 'subscriptions:renewals', 'subscriptions:billing-run',
    'subscriptions:revenue-recognition', 'appointments:reminders',
];

beforeEach(function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = PrivacyFixtures::tenant('Statutory AG');
});

afterEach(fn () => app(TenantContext::class)->clear());

/**
 * Name of a scheduled event: its name or job class, or the artisan command without arguments.
 */
function statutoryScheduleName(Event $event): string
{
    $name = $event instanceof CallbackEvent ? (string) $event->description : (string) preg_replace("/^.*?artisan'?\s+/", '', (string) $event->command);

    return explode(' ', $name)[0];
}

function statutoryDocument(Tenant $tenant, ?string $type, string $createdAt, DmsRetentionPolicy $policy): DmsDocument
{
    $document = DmsDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'document_type' => $type,
        'retention_policy_id' => $policy->id,
        'retention_until' => now()->subDay()->toDateString(),
    ]);
    DmsDocument::withoutGlobalScopes()->whereKey($document->id)->toBase()->update(['created_at' => $createdAt]);

    return $document;
}

function statutoryEmployeeDocument(Tenant $tenant, Employee $employee, string $type, string $createdAt): EmployeeDocument
{
    $document = EmployeeDocument::factory()->create([
        'tenant_id' => $tenant->id,
        'employee_id' => $employee->id,
        'document_type' => $type,
        'file_path' => 'hr/'.Str::uuid().'.pdf',
        'retention_until' => now()->subDay()->toDateString(),
    ]);
    EmployeeDocument::withoutGlobalScopes()->whereKey($document->id)->toBase()->update(['created_at' => $createdAt]);
    Storage::disk('local')->put($document->file_path, 'x');

    return $document;
}

test('every registry entry that is not erasable has a catalogue rule, and every preservation class is a fiscal record', function (): void {
    foreach ((new PersonalDataRegistry)->entries() as $entry) {
        expect(StatutoryRetentionCatalogue::covers($entry->retention))->toBe(! $entry->retention->isErasable(), $entry->key());
    }

    foreach (PreservationDocumentClass::cases() as $class) {
        expect(StatutoryRetentionCatalogue::retentionForDocumentType($class->value))->toBe(PersonalDataRetention::FiscalRecord)
            ->and(StatutoryRetentionCatalogue::minimumYears(PersonalDataRetention::FiscalRecord))->toBe($class->suggestedRetentionYears());
    }

    expect(StatutoryRetentionCatalogue::retentionForDocumentType('brochure'))->toBeNull()
        ->and(StatutoryRetentionCatalogue::retentionForDocumentType(null))->toBeNull();
});

test('the catalogue holds a record until its period elapsed and evidence indefinitely', function (): void {
    $fiscal = PersonalDataRetention::FiscalRecord;

    expect(StatutoryRetentionCatalogue::isUnderRetention($fiscal, now()->subYears(9)))->toBeTrue()
        ->and(StatutoryRetentionCatalogue::isUnderRetention($fiscal, now()->subYears(10)->subDay()))->toBeFalse()
        ->and(StatutoryRetentionCatalogue::isUnderRetention(PersonalDataRetention::StatutoryEmployment, now()->subYears(4)))->toBeTrue()
        ->and(StatutoryRetentionCatalogue::isUnderRetention(PersonalDataRetention::StatutoryEmployment, now()->subYears(6)))->toBeFalse()
        ->and(StatutoryRetentionCatalogue::isUnderRetention(PersonalDataRetention::Evidence, now()->subYears(50)))->toBeTrue()
        ->and(StatutoryRetentionCatalogue::isUnderRetention(PersonalDataRetention::Erasable, now()->subYears(50)))->toBeFalse();
});

test('dms:retention keeps statutory documents inside their period and records the skip', function (): void {
    $policy = DmsRetentionPolicy::factory()->create(['tenant_id' => $this->tenant->id, 'retention_months' => 1, 'action' => 'delete']);
    $payslip = statutoryDocument($this->tenant, 'payslip', now()->subYears(2)->toDateTimeString(), $policy);
    $ledger = statutoryDocument($this->tenant, 'ledger_book', now()->subYears(9)->toDateTimeString(), $policy);
    $elapsed = statutoryDocument($this->tenant, 'payslip', now()->subYears(11)->toDateTimeString(), $policy);
    $other = statutoryDocument($this->tenant, null, now()->subYears(2)->toDateTimeString(), $policy);

    app(TenantContext::class)->runWithTenant($this->tenant, fn () => (new ApplyDocumentRetentionJob((string) $this->tenant->id))->handle(app(DocumentRetention::class)));

    $exists = fn (DmsDocument $document): bool => DmsDocument::withoutGlobalScopes()->whereKey($document->id)->whereNull('deleted_at')->exists();
    expect($exists($payslip))->toBeTrue()
        ->and($exists($ledger))->toBeTrue()
        ->and($exists($elapsed))->toBeFalse()
        ->and($exists($other))->toBeFalse()
        ->and(AuditLedgerEntry::query()->where('action', 'DOCUMENT_RETENTION_HELD_STATUTORY')->count())->toBe(2);
});

test('hr:purge-employee-documents keeps payslips inside their period even after the retention date', function (): void {
    $employee = Employee::factory()->active()->create(['tenant_id' => $this->tenant->id]);
    $payslip = statutoryEmployeeDocument($this->tenant, $employee, 'payslip', now()->subYears(9)->toDateTimeString());
    $oldPayslip = statutoryEmployeeDocument($this->tenant, $employee, 'payslip', now()->subYears(11)->toDateTimeString());
    $warning = statutoryEmployeeDocument($this->tenant, $employee, 'warning', now()->subYears(1)->toDateTimeString());

    (new PurgeExpiredEmployeeDocumentsJob((string) $this->tenant->id))->handle(app(EmployeeDocumentService::class));

    $find = fn (EmployeeDocument $document): ?EmployeeDocument => EmployeeDocument::withoutGlobalScopes()->withTrashed()->find($document->id);
    expect($find($payslip))->not->toBeNull()
        ->and($find($oldPayslip))->toBeNull()
        ->and($find($warning))->toBeNull();
    Storage::disk('local')->assertExists($payslip->file_path);
});

test('time:purge-expired keeps working time records inside five whole years, whatever the company setting', function (): void {
    $this->tenant->update(['settings' => ['preferences' => ['time_tracking' => ['retention_years' => 1]]]]);
    $userId = (string) Str::uuid7();
    $event = TimeClockEvent::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $userId, 'occurred_at' => '2022-03-01 08:00:00']);
    $record = TimeDailyRecord::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $userId, 'work_date' => '2022-03-01']);
    $expired = TimeDailyRecord::factory()->create(['tenant_id' => $this->tenant->id, 'user_id' => $userId, 'work_date' => '2020-12-31']);

    $this->artisan('time:purge-expired')->assertSuccessful();

    expect(TimeClockEvent::withoutGlobalScopes()->whereKey($event->id)->exists())->toBeTrue()
        ->and(TimeDailyRecord::withoutGlobalScopes()->whereKey($record->id)->exists())->toBeTrue()
        ->and(TimeDailyRecord::withoutGlobalScopes()->whereKey($expired->id)->exists())->toBeFalse();
});

test('privacy:apply-retention skips a policy on a fiscal table and changes no row', function (): void {
    $invoice = PrivacyFixtures::invoice($this->tenant, PrivacyFixtures::contact($this->tenant), now()->subYears(9)->toDateString());
    $before = Invoice::withoutGlobalScopes()->findOrFail($invoice->id)->getAttributes();
    PrivacyRetentionPolicy::create(['id' => (string) Str::uuid7(), 'tenant_id' => $this->tenant->id, 'entry_key' => 'invoices', 'retention_days' => 30, 'is_active' => true, 'action' => 'anonymise']);

    $this->artisan('privacy:apply-retention')->assertSuccessful();

    expect(PrivacyRetentionRun::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->where('entry_key', 'invoices')->value('status'))->toBe('skipped')
        ->and(Invoice::withoutGlobalScopes()->findOrFail($invoice->id)->getAttributes())->toBe($before);
});

test('demo:reset refuses to run unless the demo flag is set and changes nothing', function (): void {
    config(['app.demo_reset' => false]);
    $invoice = PrivacyFixtures::invoice($this->tenant, PrivacyFixtures::contact($this->tenant));

    $this->artisan('demo:reset')->assertFailed();

    expect(Invoice::withoutGlobalScopes()->whereKey($invoice->id)->exists())->toBeTrue()
        ->and(Tenant::query()->whereKey($this->tenant->id)->exists())->toBeTrue();
});

test('every scheduled entry is classified and none runs a preservation disposal', function (): void {
    $names = collect(app(Schedule::class)->events())->map(statutoryScheduleName(...))->unique()->values()->all();
    $classified = [...array_keys(SCHEDULE_CLEANUPS), ...SCHEDULE_NON_CLEANUPS];

    expect(array_values(array_diff($names, $classified)))->toBe([], 'Scheduled entries not classified for statutory retention')
        ->and(array_values(array_diff(array_keys(SCHEDULE_CLEANUPS), $names)))->toBe([], 'Classified cleanups no longer scheduled')
        ->and(collect($names)->filter(fn (string $name): bool => str_contains(strtolower($name), 'dispos'))->all())->toBe([]);
});
