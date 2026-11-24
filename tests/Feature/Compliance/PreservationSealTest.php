<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\DocumentTrust\Models\PreservationAccessLog;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;

require_once __DIR__.'/PreservationExtraHelpers.php';
require_once __DIR__.'/../Payroll/Support.php';

uses(RefreshDatabase::class);

/**
 * Two posted entries in a fiscal year of the tenant.
 */
function peLedgerYear(Tenant $tenant, int $year = 2026): string
{
    app(TenantContext::class)->setTenant($tenant);
    $fiscalYear = app(OpenFiscalYearAction::class)->execute((string) $tenant->id, $year);
    $account = fn (string $code, string $name, string $class): ChartOfAccount => ChartOfAccount::create([
        'tenant_id' => $tenant->id, 'account_code' => $code, 'name' => $name, 'account_class' => $class, 'account_type' => $class,
        'normal_balance' => $class === 'asset' ? 'debit' : 'credit', 'is_active' => true,
    ]);
    $cash = $account('1000', 'Cassa', 'asset');
    $revenue = $account('4000', 'Ricavi', 'revenue');

    foreach (['03-10' => 100.0, '04-01' => 50.5] as $day => $amount) {
        app(DoubleEntryPostingServiceInterface::class)->createAndPost([
            'tenant_id' => (string) $tenant->id,
            'fiscal_period_id' => app(FiscalPeriodResolver::class)->forDate((string) $tenant->id, "{$year}-{$day}")->id,
            'posting_date' => "{$year}-{$day}", 'entry_number' => 'RND-'.Str::upper(Str::random(6)), 'description' => "Incasso {$day}", 'status' => 'posted',
            'lines' => [['account_id' => $cash->id, 'debit_amount' => $amount, 'credit_amount' => 0.0], ['account_id' => $revenue->id, 'debit_amount' => 0.0, 'credit_amount' => $amount]],
        ]);
    }

    return (string) $fiscalYear->id;
}

function peStoredContent(string $documentId): string
{
    $version = DmsDocumentVersion::withoutGlobalScopes()->where('document_id', $documentId)->orderByDesc('version_number')->firstOrFail();

    return (string) Storage::disk($version->disk)->get($version->path);
}

beforeEach(function (): void {
    Storage::fake('local');
    config(['filesystems.private_disk' => 'local']);
    $this->tenant = complianceTenant();
    $this->year = peLedgerYear($this->tenant);
    $this->accountant = peActAs($this->tenant, 'accountant');
});

afterEach(fn () => app(TenantContext::class)->clear());

test('the ledger book is filed in the library with the same bytes as the General Ledger export', function (): void {
    $download = $this->get("/api/v1/general-ledger/journal-book/export?fiscal_year_id={$this->year}")->assertOk()->getContent();

    $filed = $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year])->assertCreated()->json('data.0');
    $document = DmsDocument::findOrFail($filed['document_id']);

    expect($filed['reused'])->toBeFalse()
        ->and(peStoredContent($filed['document_id']))->toBe($download)
        ->and($filed['sha256'])->toBe(hash('sha256', $download))
        ->and($document->document_type)->toBe('ledger_book')
        ->and($document->metadata)->toMatchArray(['document_date' => '2026-12-31', 'document_number' => 'FY2026', 'counterpart_vat_number' => '00811720580']);

    $again = $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year])->assertCreated()->json('data.0');
    expect($again['reused'])->toBeTrue()->and($again['document_id'])->toBe($filed['document_id'])->and(DmsDocument::count())->toBe(1)
        ->and(PreservationAccessLog::where('action', 'seal')->count())->toBe(2);
});

test('the filed ledger book goes straight into an open ledger_book package that can then be closed and verified', function (): void {
    $package = peOpenPackage('ledger_book');

    $filed = $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year, 'package_id' => $package])->assertCreated()->json('data.0');
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year, 'package_id' => $package])->assertCreated()->assertJsonPath('data.0.package_item_id', $filed['package_item_id']);

    $item = PreservationItem::findOrFail($filed['package_item_id']);
    expect(PreservationItem::where('package_id', $package)->count())->toBe(1)
        ->and($item->document_number)->toBe('FY2026')
        ->and($item->document_type)->toBe('ledger_book');

    $this->postJson(PE_BASE."/preservation-packages/{$package}/close")->assertOk();
    $this->postJson(PE_BASE."/preservation-packages/{$package}/verify")->assertOk()->assertJsonPath('data.result', 'passed');
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year, 'package_id' => $package])->assertConflict();
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year, 'package_id' => peOpenPackage('payslip')])->assertUnprocessable();
});

test('the ledger seal is validated and refuses what is not the tenant own', function (): void {
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', [])->assertUnprocessable();
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => (string) Str::uuid()])->assertNotFound();

    $foreignPackage = peOpenPackage('ledger_book');
    $beta = Tenant::create(['name' => 'Beta', 'legal_name' => 'Beta Srl', 'vat_number' => '00000000019', 'country' => 'IT', 'default_currency' => 'EUR']);
    $betaYear = peLedgerYear($beta);
    peActAs($beta, 'accountant');

    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year])->assertNotFound();
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $betaYear, 'package_id' => $foreignPackage])->assertNotFound();
    expect(DmsDocument::withoutGlobalScopes()->count())->toBe(0);
});

test('the payslips of a closed run are rendered with the payroll PDF and filed, one document each', function (): void {
    $this->travelTo('2026-11-06 10:00:00');
    $tenant = payrollTenant();
    $manager = payrollMember($tenant, 'admin');
    $first = payrollEmployee($tenant);
    $second = payrollEmployee($tenant, ['first_name' => 'Anna', 'last_name' => 'Bianchi', 'full_name' => 'Anna Bianchi']);
    payrollProfile($tenant, $first);
    payrollProfile($tenant, $second);
    payrollSwissSetup($tenant, $manager);
    $openRunId = payrollCalculate($tenant, $manager, 2026, 1)->assertCreated()->json('data.id');

    payrollApi($manager, $tenant)->postJson(PE_BASE.'/preservation-seal/payslips', ['year' => 2026, 'month' => 1])->assertUnprocessable();

    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$openRunId}/approve")->assertOk();
    payrollApi($manager, $tenant)->postJson("/api/v1/payroll/runs/{$openRunId}/finalize")->assertOk();
    $package = payrollApi($manager, $tenant)->postJson(PE_BASE.'/preservation-packages', ['document_class' => 'payslip', 'name' => 'Cedolini 01/2026'])->assertCreated()->json('data.id');

    $filed = payrollApi($manager, $tenant)->postJson(PE_BASE.'/preservation-seal/payslips', ['year' => 2026, 'month' => 1, 'package_id' => $package])->assertCreated()->json('data');

    expect($filed)->toHaveCount(2)
        ->and(collect($filed)->pluck('reused')->unique()->all())->toBe([false])
        ->and(peStoredContent($filed[0]['document_id']))->toStartWith('%PDF')
        ->and(PreservationItem::where('package_id', $package)->pluck('counterpart_name')->contains('Anna Bianchi'))->toBeTrue()
        ->and(PreservationItem::where('package_id', $package)->pluck('document_type')->unique()->all())->toBe(['payslip']);

    $again = payrollApi($manager, $tenant)->postJson(PE_BASE.'/preservation-seal/payslips', ['year' => 2026, 'month' => 1, 'package_id' => $package])->assertCreated()->json('data');

    expect(collect($again)->pluck('reused')->unique()->all())->toBe([true])
        ->and(collect($again)->pluck('document_id')->sort()->values()->all())->toBe(collect($filed)->pluck('document_id')->sort()->values()->all())
        ->and(PreservationItem::where('package_id', $package)->count())->toBe(2);

    payrollApi($manager, $tenant)->postJson(PE_BASE."/preservation-packages/{$package}/close")->assertOk();
    payrollApi($manager, $tenant)->postJson(PE_BASE."/preservation-packages/{$package}/verify")->assertOk()->assertJsonPath('data.result', 'passed');

    $other = payrollTenant();
    $otherManager = payrollMember($other, 'admin');
    payrollApi($otherManager, $other)->postJson(PE_BASE.'/preservation-seal/payslips', ['year' => 2026, 'month' => 1])->assertUnprocessable();
    payrollApi($otherManager, $other)->postJson(PE_BASE.'/preservation-seal/payslips', ['year' => 2026, 'month' => 1, 'package_id' => $package])->assertNotFound();
    $this->travelBack();
});

test('sealing needs access to the module it reads from', function (): void {
    $this->postJson(PE_BASE.'/preservation-seal/payslips', ['year' => 2026, 'month' => 1])->assertForbidden();

    peActAs($this->tenant, 'member');
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year])->assertForbidden();

    peActAs($this->tenant, 'accountant_readonly');
    $this->postJson(PE_BASE.'/preservation-seal/journal-book', ['fiscal_year_id' => $this->year])->assertForbidden();
    expect(DmsDocument::withoutGlobalScopes()->count())->toBe(0);
});
