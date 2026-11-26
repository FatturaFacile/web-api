<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\FiduciaryPortalLocalizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\FiduciaryPortal\Domain\Enums\StudioExportFormat;
use Modules\FiduciaryPortal\Models\FiduciaryAuditLog;
use Modules\FiduciaryPortal\Services\DigitalBalanceSheetDossierBuilder;
use Modules\FiduciaryPortal\Services\FiduciaryEntryNoteService;
use Modules\FiduciaryPortal\Services\FiduciaryJournalReviewService;
use Modules\FiduciaryPortal\Services\FiduciaryMultiTenantOverviewService;
use Modules\FiduciaryPortal\Services\FiduciaryPeriodLockService;
use Modules\FiduciaryPortal\Services\FiduciaryTranscodingService;
use Modules\FiduciaryPortal\Services\StudioExportEngine;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;

uses(RefreshDatabase::class);

function seedFiduciaryContext(): array
{
    $tenantIt = Tenant::factory()->create(['country' => 'IT', 'default_currency' => 'EUR', 'vat_number' => 'IT01234560153']);
    $tenantCh = Tenant::factory()->create(['country' => 'CH', 'default_currency' => 'CHF', 'uid_number' => 'CHE-114.892.451']);

    $fiduciary = User::factory()->create(['name' => 'Dott.ssa Chiara Bernasconi']);
    $tenantIt->users()->attach($fiduciary->id, ['id' => (string) Str::uuid7(), 'role' => 'external_fiduciary', 'permissions' => json_encode(['fiduciary_portal.*', 'gl.*'])]);
    $tenantCh->users()->attach($fiduciary->id, ['id' => (string) Str::uuid7(), 'role' => 'external_fiduciary', 'permissions' => json_encode(['fiduciary_portal.*', 'gl.*'])]);

    app(TenantContext::class)->setTenant($tenantIt);

    $fy = FiscalYear::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'year_code' => '2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    $period = FiscalPeriod::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'fiscal_year_id' => $fy->id,
        'period_number' => 1,
        'period_name' => '2026-01',
        'start_date' => '2026-01-01',
        'end_date' => '2026-01-31',
        'status' => 'open',
    ]);

    $accDebit = ChartOfAccount::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'account_code' => '1100',
        'name' => 'Banca Intesa',
        'account_type' => 'asset',
        'normal_balance' => 'debit',
        'is_active' => true,
        'is_postable' => true,
    ]);

    $accCredit = ChartOfAccount::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'account_code' => '4100',
        'name' => 'Ricavi Servizi',
        'account_type' => 'revenue',
        'normal_balance' => 'credit',
        'is_active' => true,
        'is_postable' => true,
    ]);

    $entry = JournalEntry::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'fiscal_period_id' => $period->id,
        'entry_number' => 'JE-2026-0001',
        'posting_date' => '2026-01-15',
        'document_date' => '2026-01-15',
        'ledger_type' => 'statutory',
        'journal_type' => 'standard',
        'description' => 'Fattura attiva consulenza',
        'status' => 'posted',
        'total_amount' => '1220.0000',
    ]);

    JournalLine::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'journal_entry_id' => $entry->id,
        'posting_date' => '2026-01-15',
        'line_number' => 1,
        'account_id' => $accDebit->id,
        'description' => 'Incasso',
        'debit_amount' => '1220.0000',
        'credit_amount' => '0.0000',
        'functional_debit' => '1220.0000',
        'functional_credit' => '0.0000',
        'currency' => 'EUR',
        'exchange_rate' => '1.000000',
    ]);

    JournalLine::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'journal_entry_id' => $entry->id,
        'posting_date' => '2026-01-15',
        'line_number' => 2,
        'account_id' => $accCredit->id,
        'description' => 'Ricavo',
        'debit_amount' => '0.0000',
        'credit_amount' => '1220.0000',
        'functional_debit' => '0.0000',
        'functional_credit' => '1220.0000',
        'currency' => 'EUR',
        'exchange_rate' => '1.000000',
    ]);

    return [$tenantIt, $tenantCh, $fiduciary, $fy, $period, $accDebit, $accCredit, $entry];
}

test('5-locale fiduciary localization seeder populates all required keys across it-CH, de-CH, fr-CH, en, it-IT', function (): void {
    $this->seed(FiduciaryPortalLocalizationSeeder::class);

    foreach (['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'] as $locale) {
        expect(Translation::query()->whereNull('tenant_id')->where('locale', $locale)->where('key', 'NAV.FIDUCIARY_PORTAL')->exists())->toBeTrue()
            ->and(Translation::query()->whereNull('tenant_id')->where('locale', $locale)->where('key', 'fiduciaryPortal.badge.verified')->exists())->toBeTrue()
            ->and(Translation::query()->whereNull('tenant_id')->where('locale', $locale)->where('key', 'fiduciaryPortal.exportFormat.abacus_xml.label')->exists())->toBeTrue();
    }
});

test('multi-tenant overview, verification, transcoding, notes, export engine, period lock enforcement, and dossier ZIP work end-to-end', function (): void {
    [$tenantIt, $tenantCh, $fiduciary, $fy, $period, $accDebit, $accCredit, $entry] = seedFiduciaryContext();

    // 1. Multi-tenant overview lists both assigned clients
    $overview = app(FiduciaryMultiTenantOverviewService::class)->overview($fiduciary, $tenantIt->id);
    expect($overview['clients'])->toHaveCount(2)
        ->and($overview['active_client']['unverified_entries_count'])->toBe(1);

    // 2. Verify entry as Verificato dal Fiduciario
    $verifyResult = app(FiduciaryJournalReviewService::class)->verifyEntries($tenantIt->id, [$entry->id], true, 'Checked against bank statement', $fiduciary);
    expect($verifyResult['updated'])->toBe(1)
        ->and($entry->fresh()->fiduciary_verified)->toBeTrue();

    // 3. Anchored entry note & resolution
    $noteService = app(FiduciaryEntryNoteService::class);
    $note = $noteService->createNote($tenantIt->id, $entry->id, 'Please attach signed PDF.', $fiduciary);
    expect($note->status)->toBe('open');
    $resolved = $noteService->resolveNote($tenantIt->id, $note->id, true, 'PDF attached.', $fiduciary);
    expect($resolved->status)->toBe('resolved');

    // 4. Custom Chart of Accounts Transcoding Matrix & Studio Export Engine
    $transcoding = app(FiduciaryTranscodingService::class);
    $transcoding->saveRule($tenantIt->id, [
        'studio_system' => StudioExportFormat::TeamSystem->value,
        'source_account_id' => $accDebit->id,
        'studio_account_code' => '1010001',
        'studio_account_name' => 'Banca c/c Studio',
    ], $fiduciary);

    $exportEngine = app(StudioExportEngine::class);
    $diag = $exportEngine->validate($tenantIt->id, [
        'format_code' => StudioExportFormat::TeamSystem->value,
        'from_date' => '2026-01-01',
        'to_date' => '2026-01-31',
        'apply_transcoding' => true,
    ]);
    expect($diag['entries_count'])->toBe(1)
        ->and($diag['unmapped_accounts_count'])->toBe(1);

    $run = $exportEngine->generate($tenantIt->id, [
        'format_code' => StudioExportFormat::TeamSystem->value,
        'from_date' => '2026-01-01',
        'to_date' => '2026-01-31',
        'apply_transcoding' => true,
    ], $fiduciary);
    expect($run->content_payload)->toContain('1010001');

    // 5. Period Lock & strict mutation blocking
    $lockService = app(FiduciaryPeriodLockService::class);
    $lockedPeriod = $lockService->lockPeriod($tenantIt->id, $period->id, 'Monthly VAT & books closed', $fiduciary);
    expect($lockedPeriod->status)->toBe('locked_by_fiduciary')
        ->and($lockedPeriod->isFiduciaryLocked())->toBeTrue();

    expect(fn () => JournalEntry::query()->create([
        'id' => (string) Str::uuid7(),
        'tenant_id' => $tenantIt->id,
        'fiscal_period_id' => $period->id,
        'entry_number' => 'JE-2026-RETRO',
        'posting_date' => '2026-01-20',
        'document_date' => '2026-01-20',
        'status' => 'draft',
        'total_amount' => '100.0000',
    ]))->toThrow(LogicException::class);

    expect(FiduciaryAuditLog::query()->where('tenant_id', $tenantIt->id)->where('action', 'locked_period_mutation_blocked')->exists())->toBeTrue();

    // 6. Digital Balance Sheet Dossier ZIP (handles empty datasets cleanly)
    $dossier = app(DigitalBalanceSheetDossierBuilder::class)->build($tenantIt->id, $fy->id, null, null, 'teamsystem', $fiduciary);
    expect(file_exists($dossier['path']))->toBeTrue()
        ->and($dossier['manifest']['datasets']['journal_entries'])->toBe(1)
        ->and($dossier['manifest']['datasets']['active_invoices'])->toBe(0);
    @unlink($dossier['path']);
});
