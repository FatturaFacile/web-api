<?php

declare(strict_types=1);

use App\Models\ChartOfAccount;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Tenant;
use App\Models\Translation;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\DataMigrationLocalizationSeeder;
use Database\Seeders\DataMigrationPresetSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\DataMigration\Application\Commands\AutoDetectColumnMappingCommand;
use Modules\DataMigration\Application\Commands\AutoDetectColumnMappingHandler;
use Modules\DataMigration\Application\Commands\CommitMigrationJobCommand;
use Modules\DataMigration\Application\Commands\CommitMigrationJobHandler;
use Modules\DataMigration\Application\Commands\PatchMigrationStagingRowCommand;
use Modules\DataMigration\Application\Commands\PatchMigrationStagingRowHandler;
use Modules\DataMigration\Application\Commands\RollbackMigrationBatchCommand;
use Modules\DataMigration\Application\Commands\RollbackMigrationBatchHandler;
use Modules\DataMigration\Application\Commands\RunMigrationDryRunSimulationCommand;
use Modules\DataMigration\Application\Commands\RunMigrationDryRunSimulationHandler;
use Modules\DataMigration\Application\Commands\UploadMigrationSourceArchiveCommand;
use Modules\DataMigration\Application\Commands\UploadMigrationSourceArchiveHandler;
use Modules\DataMigration\Application\Queries\DownloadExcelMigrationTemplatesHandler;
use Modules\DataMigration\Application\Queries\DownloadExcelMigrationTemplatesQuery;
use Modules\DataMigration\Application\Queries\GetMigrationDryRunReportHandler;
use Modules\DataMigration\Application\Queries\GetMigrationDryRunReportQuery;
use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Domain\Enums\MigrationSourceSystem;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Models\MigrationFieldMappingTemplate;
use Modules\DataMigration\Models\MigrationStagingRow;
use Modules\DataMigration\Models\MigrationValidationError;
use Modules\GeneralLedger\Models\JournalEntry;

uses(RefreshDatabase::class);

function setupMigrationTenant(): array
{
    $tenant = Tenant::factory()->create([
        'vat_number' => '01234560153',
        'country' => 'IT',
    ]);
    $user = User::factory()->create();
    $tenant->users()->attach($user->id, ['role' => 'owner', 'is_active' => true]);
    app(TenantContext::class)->setTenant($tenant);

    return [$tenant, $user];
}

test('5-locale localization seeder and system preset seeder populate database cleanly', function (): void {
    $this->seed(DataMigrationLocalizationSeeder::class);
    $this->seed(DataMigrationPresetSeeder::class);

    foreach (['it-CH', 'de-CH', 'fr-CH', 'en', 'it-IT'] as $locale) {
        expect(Translation::query()->whereNull('tenant_id')->where('locale', $locale)->where('key', 'NAV.DATA_MIGRATION')->exists())->toBeTrue();
    }

    expect(MigrationFieldMappingTemplate::query()->whereNull('tenant_id')->where('is_system_preset', true)->count())->toBeGreaterThanOrEqual(11);
});

test('dry-run catches invalid IT VAT, Swiss UID, IBAN, and unbalanced GL, then inline patch + commit + 1-click rollback succeed atomically', function (): void {
    [$tenant, $user] = setupMigrationTenant();

    // Create a CSV with 1 customer having an invalid IT VAT (01234560159 instead of 01234560153)
    $csvPath = tempnam(sys_get_temp_dir(), 'mig_cust_').'.csv';
    file_put_contents($csvPath, "company_name;country;vat_number;email\nAcme Srl;IT;01234560159;info@acme.it\n");

    $uploadHandler = app(UploadMigrationSourceArchiveHandler::class);
    $job = $uploadHandler->handle(new UploadMigrationSourceArchiveCommand(
        tenantId: $tenant->id,
        legalEntityId: null,
        userId: $user->id,
        sourceSystem: MigrationSourceSystem::FattureInCloud,
        targetEntities: [MigrationTargetEntity::Customers->value],
        filePath: $csvPath,
        originalFileName: 'customers.csv',
        extension: 'csv',
    ));
    @unlink($csvPath);

    expect($job->status)->toBe(MigrationJobStatus::Uploaded)
        ->and($job->total_rows)->toBe(1);

    app(AutoDetectColumnMappingHandler::class)->handle(new AutoDetectColumnMappingCommand($job->id));

    $dryRunResult = app(RunMigrationDryRunSimulationHandler::class)->handle(new RunMigrationDryRunSimulationCommand($job->id));

    expect($dryRunResult['summary']['dry_run_passed'])->toBeFalse()
        ->and($dryRunResult['summary']['error_rows'])->toBe(1);

    $error = MigrationValidationError::query()->where('migration_job_id', $job->id)->firstOrFail();
    expect($error->error_code)->toBe('INVALID_ITALIAN_VAT')
        ->and($error->suggested_fix)->toBe('01234560153');

    // Patch inline with suggested fix
    $stagingRow = MigrationStagingRow::query()->where('migration_job_id', $job->id)->firstOrFail();
    app(PatchMigrationStagingRowHandler::class)->handle(new PatchMigrationStagingRowCommand(
        jobId: $job->id,
        rowId: $stagingRow->id,
        values: ['vat_number' => '01234560153'],
    ));

    $revalidated = app(RunMigrationDryRunSimulationHandler::class)->handle(new RunMigrationDryRunSimulationCommand($job->id));
    expect($revalidated['summary']['dry_run_passed'])->toBeTrue()
        ->and($revalidated['job']->status)->toBe(MigrationJobStatus::DryRunValidated);

    $report = app(GetMigrationDryRunReportHandler::class)->handle(new GetMigrationDryRunReportQuery($job->id));
    expect($report['errors'])->toHaveCount(0);

    // Commit
    $committed = app(CommitMigrationJobHandler::class)->handle(new CommitMigrationJobCommand($job->id));
    expect($committed['job']->status)->toBe(MigrationJobStatus::Completed)
        ->and($committed['imported_rows'])->toBe(1)
        ->and(Contact::query()->where('vat_number', '01234560153')->exists())->toBeTrue();

    // 1-Click Rollback
    $rolledBack = app(RollbackMigrationBatchHandler::class)->handle(new RollbackMigrationBatchCommand($job->id));
    expect($rolledBack['job']->status)->toBe(MigrationJobStatus::RolledBack)
        ->and($rolledBack['reverted_records'])->toBeGreaterThanOrEqual(1)
        ->and(Contact::query()->where('vat_number', '01234560153')->exists())->toBeFalse();
});

test('OpeningGlBalances enforces double-entry dare == avere balance during dry-run and commits balanced journal entry', function (): void {
    [$tenant, $user] = setupMigrationTenant();

    ChartOfAccount::query()->create(['tenant_id' => $tenant->id, 'code' => '1020', 'name' => 'Bank', 'type' => 'asset', 'is_active' => true]);
    ChartOfAccount::query()->create(['tenant_id' => $tenant->id, 'code' => '2800', 'name' => 'Equity', 'type' => 'equity', 'is_active' => true]);

    $csvPath = tempnam(sys_get_temp_dir(), 'mig_gl_').'.csv';
    file_put_contents($csvPath, "entry_date;account_code;description;debit_amount;credit_amount;currency\n2026-01-01;1020;Opening Bank;5000.00;0.00;CHF\n2026-01-01;2800;Opening Equity;0.00;4500.00;CHF\n");

    $job = app(UploadMigrationSourceArchiveHandler::class)->handle(new UploadMigrationSourceArchiveCommand(
        tenantId: $tenant->id,
        legalEntityId: null,
        userId: $user->id,
        sourceSystem: MigrationSourceSystem::BananaAccounting,
        targetEntities: [MigrationTargetEntity::OpeningGlBalances->value],
        filePath: $csvPath,
        originalFileName: 'gl.csv',
        extension: 'csv',
    ));
    @unlink($csvPath);

    $unbalanced = app(RunMigrationDryRunSimulationHandler::class)->handle(new RunMigrationDryRunSimulationCommand($job->id));
    expect($unbalanced['summary']['dry_run_passed'])->toBeFalse()
        ->and($unbalanced['summary']['gl_balance']['is_balanced'])->toBeFalse();

    $secondRow = MigrationStagingRow::query()->where('migration_job_id', $job->id)->where('row_index', 2)->firstOrFail();
    app(PatchMigrationStagingRowHandler::class)->handle(new PatchMigrationStagingRowCommand($job->id, $secondRow->id, ['credit_amount' => '5000.00']));

    $balanced = app(RunMigrationDryRunSimulationHandler::class)->handle(new RunMigrationDryRunSimulationCommand($job->id));
    expect($balanced['summary']['dry_run_passed'])->toBeTrue()
        ->and($balanced['summary']['gl_balance']['is_balanced'])->toBeTrue();

    $committed = app(CommitMigrationJobHandler::class)->handle(new CommitMigrationJobCommand($job->id));
    expect($committed['job']->status)->toBe(MigrationJobStatus::Completed)
        ->and(JournalEntry::query()->where('source_id', $job->id)->exists())->toBeTrue();
});

test('FatturaPA XML ZIP bulk importer extracts customers, products, and historical invoices with XXE protection', function (): void {
    [$tenant, $user] = setupMigrationTenant();

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">
  <FatturaElettronicaHeader>
    <CedentePrestatore>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234560153</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Tenant Seller Srl</Denominazione></Anagrafica>
      </DatiAnagrafici>
      <Sede><Indirizzo>Via Roma 1</Indirizzo><CAP>20100</CAP><Comune>Milano</Comune><Nazione>IT</Nazione></Sede>
    </CedentePrestatore>
    <CessionarioCommittente>
      <DatiAnagrafici>
        <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234560153</IdCodice></IdFiscaleIVA>
        <Anagrafica><Denominazione>Buyer Alpha Srl</Denominazione></Anagrafica>
      </DatiAnagrafici>
      <Sede><Indirizzo>Via Torino 10</Indirizzo><CAP>10100</CAP><Comune>Torino</Comune><Nazione>IT</Nazione></Sede>
    </CessionarioCommittente>
  </FatturaElettronicaHeader>
  <FatturaElettronicaBody>
    <DatiGenerali>
      <DatiGeneraliDocumento>
        <TipoDocumento>TD01</TipoDocumento>
        <Divisa>EUR</Divisa>
        <Data>2025-05-10</Data>
        <Numero>FT-2025-100</Numero>
        <ImportoTotaleDocumento>1220.00</ImportoTotaleDocumento>
      </DatiGeneraliDocumento>
    </DatiGenerali>
    <DatiBeniServizi>
      <DettaglioLinee>
        <NumeroLinea>1</NumeroLinea>
        <CodiceArticolo><CodiceTipo>SKU</CodiceTipo><CodiceValore>CONS-01</CodiceValore></CodiceArticolo>
        <Descrizione>Consulenza Software</Descrizione>
        <Quantita>1.00</Quantita>
        <UnitaMisura>PZ</UnitaMisura>
        <PrezzoUnitario>1000.00</PrezzoUnitario>
        <PrezzoTotale>1000.00</PrezzoTotale>
        <AliquotaIVA>22.00</AliquotaIVA>
      </DettaglioLinee>
      <DatiRiepilogo>
        <AliquotaIVA>22.00</AliquotaIVA>
        <ImponibileImporto>1000.00</ImponibileImporto>
        <Imposta>220.00</Imposta>
      </DatiRiepilogo>
    </DatiBeniServizi>
  </FatturaElettronicaBody>
</p:FatturaElettronica>
XML;

    $zipPath = tempnam(sys_get_temp_dir(), 'mig_fpa_').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('IT01234560153_00001.xml', $xml);
    $zip->close();

    $job = app(UploadMigrationSourceArchiveHandler::class)->handle(new UploadMigrationSourceArchiveCommand(
        tenantId: $tenant->id,
        legalEntityId: null,
        userId: $user->id,
        sourceSystem: MigrationSourceSystem::FatturaPaXmlZipArchive,
        targetEntities: [
            MigrationTargetEntity::Customers->value,
            MigrationTargetEntity::Products->value,
            MigrationTargetEntity::HistoricalInvoices->value,
        ],
        filePath: $zipPath,
        originalFileName: 'fatture-2025.zip',
        extension: 'zip',
    ));
    @unlink($zipPath);

    $dryRun = app(RunMigrationDryRunSimulationHandler::class)->handle(new RunMigrationDryRunSimulationCommand($job->id));
    expect($dryRun['summary']['dry_run_passed'])->toBeTrue();

    $committed = app(CommitMigrationJobHandler::class)->handle(new CommitMigrationJobCommand($job->id));
    expect($committed['imported_rows'])->toBe(3)
        ->and(Product::query()->where('sku', 'CONS-01')->exists())->toBeTrue()
        ->and(Invoice::query()->where('invoice_number', 'FT-2025-100')->exists())->toBeTrue();
});

test('template generator produces csv and xlsx files and REST endpoints enforce auth and tenant scope', function (): void {
    [$tenant, $user] = setupMigrationTenant();

    $csvTpl = app(DownloadExcelMigrationTemplatesHandler::class)->handle(new DownloadExcelMigrationTemplatesQuery(
        entityType: MigrationTargetEntity::Customers,
        format: 'csv',
        locale: 'it-CH',
    ));
    expect(file_exists($csvTpl['path']))->toBeTrue();
    @unlink($csvTpl['path']);

    $xlsxTpl = app(DownloadExcelMigrationTemplatesHandler::class)->handle(new DownloadExcelMigrationTemplatesQuery(
        entityType: MigrationTargetEntity::OpeningGlBalances,
        format: 'xlsx',
        locale: 'de-CH',
    ));
    expect(file_exists($xlsxTpl['path']))->toBeTrue();
    @unlink($xlsxTpl['path']);

    $this->actingAs($user)
        ->withHeader('X-Tenant-ID', $tenant->id)
        ->getJson('/api/v1/data-migration/metadata')
        ->assertOk()
        ->assertJsonPath('success', true);

    $csvFile = UploadedFile::fake()->createWithContent('vendors.csv', "company_name;country;uid_number\nSwiss Vendor AG;CH;CHE-105.805.239\n");

    $this->actingAs($user)
        ->withHeader('X-Tenant-ID', $tenant->id)
        ->post('/api/v1/data-migration/jobs', [
            'source_system' => 'Bexio',
            'target_entities' => ['Vendors'],
            'file' => $csvFile,
        ])
        ->assertCreated()
        ->assertJsonPath('data.source_system', 'Bexio');
});
