<?php

declare(strict_types=1);

namespace Modules\DataMigration\Services;

use App\Models\Contact;
use App\Models\ContactAddress;
use App\Models\EmployeeProfile;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PassiveInvoice;
use App\Models\PassiveInvoiceLine;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\DataMigration\Domain\Enums\MigrationJobStatus;
use Modules\DataMigration\Domain\Enums\MigrationTargetEntity;
use Modules\DataMigration\Models\MigrationJob;
use Modules\DataMigration\Models\MigrationStagingRow;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\CustomerLedger;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Models\SupplierLedger;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;

/**
 * Atomically commits validated staging rows into target ERP tables and records
 * a deterministic rollback manifest for 1-click reversal.
 */
final class MigrationCommitExecutor
{
    public function __construct(
        private readonly MigrationDryRunValidator $validator,
        private readonly FiscalPeriodResolver $fiscalPeriods,
    ) {}

    /**
     * @return array{job: MigrationJob, imported_rows: int, manifest_entries: int}
     */
    public function commit(MigrationJob $job): array
    {
        if (! $job->dry_run_passed || $job->error_rows > 0 || $job->status !== MigrationJobStatus::DryRunValidated) {
            $summary = $this->validator->validate($job);
            if (! ($summary['dry_run_passed'] ?? false)) {
                throw ValidationException::withMessages(['job' => [__('data_migration.dry_run_required')]]);
            }
            $job->refresh();
        }

        return DB::transaction(function () use ($job): array {
            $job->update(['status' => MigrationJobStatus::Executing]);

            $rowsByEntity = MigrationStagingRow::query()
                ->where('migration_job_id', $job->id)
                ->where('is_valid', true)
                ->orderBy('row_index')
                ->get()
                ->groupBy('entity_type');

            $manifest = [];
            $importedRows = 0;

            // 1. Chart of Accounts
            foreach ($rowsByEntity->get(MigrationTargetEntity::ChartOfAccounts->value, collect()) as $row) {
                $data = (array) ($row->mapped_payload_json ?? $row->raw_payload_json);
                $account = ChartOfAccount::query()->create([
                    'tenant_id' => $job->tenant_id,
                    'account_code' => (string) $data['code'],
                    'code' => (string) $data['code'],
                    'name' => (string) $data['name'],
                    'account_type' => (string) ($data['type'] ?? 'asset'),
                    'type' => (string) ($data['type'] ?? 'asset'),
                    'normal_balance' => in_array($data['type'] ?? 'asset', ['asset', 'expense'], true) ? 'debit' : 'credit',
                    'currency' => ! empty($data['currency']) ? strtoupper((string) $data['currency']) : 'CHF',
                    'standard' => ! empty($data['standard']) ? (string) $data['standard'] : 'ch_kmu',
                    'is_active' => true,
                ]);
                $manifest[] = ['entity' => MigrationTargetEntity::ChartOfAccounts->value, 'model' => ChartOfAccount::class, 'id' => $account->id];
                $row->update(['is_imported' => true, 'imported_record_id' => $account->id]);
                $importedRows++;
            }

            // 2. Customers & 3. Vendors
            foreach ([MigrationTargetEntity::Customers->value => 'customer', MigrationTargetEntity::Vendors->value => 'supplier'] as $entityType => $contactType) {
                foreach ($rowsByEntity->get($entityType, collect()) as $row) {
                    $data = (array) ($row->mapped_payload_json ?? $row->raw_payload_json);
                    $contact = Contact::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'type' => $contactType,
                        'legal_type' => 'company',
                        'country' => strtoupper((string) ($data['country'] ?: 'IT')),
                        'company_name' => (string) $data['company_name'],
                        'vat_number' => ! empty($data['vat_number']) ? (string) $data['vat_number'] : null,
                        'fiscal_code' => ! empty($data['fiscal_code']) ? (string) $data['fiscal_code'] : null,
                        'uid_number' => ! empty($data['uid_number']) ? (string) $data['uid_number'] : null,
                        'email' => ! empty($data['email']) ? (string) $data['email'] : null,
                        'phone' => ! empty($data['phone']) ? (string) $data['phone'] : null,
                        'iban' => ! empty($data['iban']) ? (string) $data['iban'] : null,
                        'qr_iban' => ! empty($data['qr_iban']) ? (string) $data['qr_iban'] : null,
                        'sdi_code' => ! empty($data['sdi_code']) ? (string) $data['sdi_code'] : null,
                        'pec' => ! empty($data['pec']) ? (string) $data['pec'] : null,
                        'payment_terms_days' => ! empty($data['payment_terms_days']) ? (int) $data['payment_terms_days'] : 30,
                    ]);
                    $manifest[] = ['entity' => $entityType, 'model' => Contact::class, 'id' => $contact->id];

                    if (! empty($data['street']) || ! empty($data['city']) || ! empty($data['postal_code'])) {
                        $addr = ContactAddress::query()->create([
                            'tenant_id' => $job->tenant_id,
                            'contact_id' => $contact->id,
                            'type' => 'billing',
                            'street' => (string) ($data['street'] ?? ''),
                            'postal_code' => (string) ($data['postal_code'] ?? ''),
                            'city' => (string) ($data['city'] ?? ''),
                            'country' => strtoupper((string) ($data['country'] ?: 'IT')),
                            'is_default' => true,
                        ]);
                        $manifest[] = ['entity' => $entityType, 'model' => ContactAddress::class, 'id' => $addr->id];
                    }

                    $row->update(['is_imported' => true, 'imported_record_id' => $contact->id]);
                    $importedRows++;
                }
            }

            // 4. Products
            foreach ($rowsByEntity->get(MigrationTargetEntity::Products->value, collect()) as $row) {
                $data = (array) ($row->mapped_payload_json ?? $row->raw_payload_json);
                $product = Product::query()->create([
                    'tenant_id' => $job->tenant_id,
                    'sku' => (string) $data['sku'],
                    'name' => (string) $data['name'],
                    'description' => ! empty($data['description']) ? (string) $data['description'] : null,
                    'unit_measure' => ! empty($data['unit_measure']) ? (string) $data['unit_measure'] : 'PZ',
                    'sale_price' => (float) ($data['sale_price'] ?: 0),
                    'cost_price' => (float) ($data['cost_price'] ?: 0),
                    'tax_rate' => (float) ($data['tax_rate'] ?: 0),
                    'barcode' => ! empty($data['barcode']) ? (string) $data['barcode'] : null,
                    'is_active' => true,
                ]);
                $manifest[] = ['entity' => MigrationTargetEntity::Products->value, 'model' => Product::class, 'id' => $product->id];
                $row->update(['is_imported' => true, 'imported_record_id' => $product->id]);
                $importedRows++;
            }

            // 5. Employees
            foreach ($rowsByEntity->get(MigrationTargetEntity::Employees->value, collect()) as $row) {
                $data = (array) ($row->mapped_payload_json ?? $row->raw_payload_json);
                $email = strtolower(trim((string) $data['email']));
                $user = User::query()->where('email', $email)->first();
                if ($user === null) {
                    $user = User::query()->create([
                        'name' => trim(((string) $data['first_name']).' '.((string) $data['last_name'])),
                        'email' => $email,
                        'password' => Hash::make(Str::random(32)),
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::Employees->value, 'model' => User::class, 'id' => $user->id];
                }

                $profile = EmployeeProfile::query()->create([
                    'tenant_id' => $job->tenant_id,
                    'user_id' => $user->id,
                    'social_security_number' => ! empty($data['social_security_number']) ? (string) $data['social_security_number'] : null,
                    'job_title' => ! empty($data['job_title']) ? (string) $data['job_title'] : null,
                    'department' => ! empty($data['department']) ? (string) $data['department'] : null,
                    'hire_date' => ! empty($data['hire_date']) ? (string) $data['hire_date'] : now()->toDateString(),
                    'base_salary' => ! empty($data['base_salary']) ? (float) $data['base_salary'] : 0.0,
                    'currency' => ! empty($data['currency']) ? strtoupper((string) $data['currency']) : 'CHF',
                ]);
                $manifest[] = ['entity' => MigrationTargetEntity::Employees->value, 'model' => EmployeeProfile::class, 'id' => $profile->id];
                $row->update(['is_imported' => true, 'imported_record_id' => $profile->id]);
                $importedRows++;
            }

            // 6. Opening GL Balances -> JournalEntry + JournalLine
            $glRows = $rowsByEntity->get(MigrationTargetEntity::OpeningGlBalances->value, collect());
            if ($glRows->isNotEmpty()) {
                $firstGl = (array) ($glRows->first()->mapped_payload_json ?? $glRows->first()->raw_payload_json);
                $entryDate = (string) ($firstGl['entry_date'] ?: now()->toDateString());
                $period = $this->fiscalPeriods->forDate((string) $job->tenant_id, $entryDate);
                $entry = JournalEntry::query()->create([
                    'tenant_id' => $job->tenant_id,
                    'fiscal_period_id' => $period->id,
                    'fiscal_year_id' => $period->fiscal_year_id,
                    'entry_number' => 'MIG-OPEN-'.substr($job->id, 0, 8),
                    'posting_date' => $entryDate,
                    'document_date' => $entryDate,
                    'description' => 'Opening Balances Migration ('.$job->source_system->value.')',
                    'status' => 'draft',
                    'source_document_type' => 'data_migration',
                    'source_document_id' => $job->id,
                ]);
                $manifest[] = ['entity' => MigrationTargetEntity::OpeningGlBalances->value, 'model' => JournalEntry::class, 'id' => $entry->id];

                $lineNumber = 1;
                foreach ($glRows as $row) {
                    $data = (array) ($row->mapped_payload_json ?? $row->raw_payload_json);
                    $accountId = ChartOfAccount::query()->where('code', (string) $data['account_code'])->value('id');
                    if ($accountId === null) {
                        continue;
                    }
                    $debit = (float) ($data['debit_amount'] ?? 0);
                    $credit = (float) ($data['credit_amount'] ?? 0);
                    $currency = ! empty($data['currency']) ? strtoupper((string) $data['currency']) : 'CHF';

                    $line = JournalLine::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'journal_entry_id' => $entry->id,
                        'account_id' => $accountId,
                        'line_number' => $lineNumber++,
                        'posting_date' => $entryDate,
                        'description' => (string) ($data['description'] ?? 'Opening balance'),
                        'debit_amount' => $debit,
                        'credit_amount' => $credit,
                        'functional_debit' => $debit,
                        'functional_credit' => $credit,
                        'currency' => $currency,
                        'exchange_rate' => 1.0,
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::OpeningGlBalances->value, 'model' => JournalLine::class, 'id' => $line->id];
                    $row->update(['is_imported' => true, 'imported_record_id' => $line->id]);
                    $importedRows++;
                }
            }

            // 7. Open AR/AP Items
            foreach ($rowsByEntity->get(MigrationTargetEntity::OpenArApItems->value, collect()) as $row) {
                $data = (array) ($row->mapped_payload_json ?? $row->raw_payload_json);
                $side = strtoupper((string) ($data['ledger_side'] ?? 'AR'));
                $contact = $this->findOrCreateCounterparty(
                    $job->tenant_id,
                    (string) ($data['counterparty_name'] ?? 'Counterparty'),
                    ! empty($data['counterparty_tax_id']) ? (string) $data['counterparty_tax_id'] : null,
                    $side === 'AP' ? 'supplier' : 'customer',
                    $manifest,
                );

                if ($side === 'AP') {
                    $item = SupplierLedger::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'supplier_id' => $contact->id,
                        'document_number' => (string) $data['document_number'],
                        'posting_date' => (string) $data['posting_date'],
                        'due_date' => (string) $data['due_date'],
                        'original_amount' => (float) $data['original_amount'],
                        'open_amount' => (float) $data['open_amount'],
                        'currency' => strtoupper((string) ($data['currency'] ?: 'EUR')),
                        'status' => 'open',
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::OpenArApItems->value, 'model' => SupplierLedger::class, 'id' => $item->id];
                } else {
                    $item = CustomerLedger::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'customer_id' => $contact->id,
                        'document_number' => (string) $data['document_number'],
                        'posting_date' => (string) $data['posting_date'],
                        'due_date' => (string) $data['due_date'],
                        'original_amount' => (float) $data['original_amount'],
                        'open_amount' => (float) $data['open_amount'],
                        'currency' => strtoupper((string) ($data['currency'] ?: 'EUR')),
                        'status' => 'open',
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::OpenArApItems->value, 'model' => CustomerLedger::class, 'id' => $item->id];
                }

                $row->update(['is_imported' => true, 'imported_record_id' => $item->id]);
                $importedRows++;
            }

            // 8. Historical Invoices (active & passive)
            foreach ($rowsByEntity->get(MigrationTargetEntity::HistoricalInvoices->value, collect()) as $row) {
                $data = (array) ($row->mapped_payload_json ?? $row->raw_payload_json);
                $direction = strtolower((string) ($data['direction'] ?? 'active'));
                $contact = $this->findOrCreateCounterparty(
                    $job->tenant_id,
                    (string) ($data['counterparty_name'] ?? 'Counterparty'),
                    ! empty($data['counterparty_vat']) ? (string) $data['counterparty_vat'] : null,
                    $direction === 'passive' ? 'supplier' : 'customer',
                    $manifest,
                    ! empty($data['counterparty_country']) ? (string) $data['counterparty_country'] : 'IT',
                );

                if ($direction === 'passive') {
                    $passive = PassiveInvoice::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'supplier_id' => $contact->id,
                        'invoice_number' => (string) $data['invoice_number'],
                        'date' => (string) $data['date'],
                        'due_date' => ! empty($data['due_date']) ? (string) $data['due_date'] : null,
                        'total_net' => (float) $data['total_net'],
                        'total_tax' => (float) $data['total_tax'],
                        'total_amount' => (float) $data['total_gross'],
                        'currency' => strtoupper((string) ($data['currency'] ?: 'EUR')),
                        'document_type' => 'TD01',
                        'payment_status' => 'unpaid',
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::HistoricalInvoices->value, 'model' => PassiveInvoice::class, 'id' => $passive->id];

                    $pLine = PassiveInvoiceLine::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'passive_invoice_id' => $passive->id,
                        'line_number' => 1,
                        'description' => (string) ($data['line_description'] ?: 'Imported invoice line'),
                        'quantity' => 1,
                        'unit_price' => (float) $data['total_net'],
                        'line_total' => (float) $data['total_net'],
                        'tax_rate' => (float) $data['total_net'] > 0 ? round(((float) $data['total_tax'] / (float) $data['total_net']) * 100, 2) : 0,
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::HistoricalInvoices->value, 'model' => PassiveInvoiceLine::class, 'id' => $pLine->id];
                    $row->update(['is_imported' => true, 'imported_record_id' => $passive->id]);
                } else {
                    $invoice = Invoice::query()->create([
                        'tenant_id' => $job->tenant_id,
                        'contact_id' => $contact->id,
                        'invoice_type' => 'invoice',
                        'invoice_number' => (string) $data['invoice_number'],
                        'date' => (string) $data['date'],
                        'due_date' => ! empty($data['due_date']) ? (string) $data['due_date'] : null,
                        'currency' => strtoupper((string) ($data['currency'] ?: 'EUR')),
                        'total_net' => (float) $data['total_net'],
                        'total_tax' => (float) $data['total_tax'],
                        'total_gross' => (float) $data['total_gross'],
                        'payment_status' => 'unpaid',
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::HistoricalInvoices->value, 'model' => Invoice::class, 'id' => $invoice->id];

                    $iLine = InvoiceItem::query()->create([
                        'invoice_id' => $invoice->id,
                        'description' => (string) ($data['line_description'] ?: 'Imported invoice line'),
                        'quantity' => 1,
                        'unit_price' => (float) $data['total_net'],
                        'line_total' => (float) $data['total_net'],
                        'tax_rate' => (float) $data['total_net'] > 0 ? round(((float) $data['total_tax'] / (float) $data['total_net']) * 100, 2) : 0,
                    ]);
                    $manifest[] = ['entity' => MigrationTargetEntity::HistoricalInvoices->value, 'model' => InvoiceItem::class, 'id' => $iLine->id];
                    $row->update(['is_imported' => true, 'imported_record_id' => $invoice->id]);
                }

                $importedRows++;
            }

            $job->update([
                'status' => MigrationJobStatus::Completed,
                'imported_rows' => $importedRows,
                'rollback_manifest_json' => ['created_records' => $manifest],
                'completed_at' => now(),
            ]);

            return [
                'job' => $job->fresh() ?? $job,
                'imported_rows' => $importedRows,
                'manifest_entries' => count($manifest),
            ];
        });
    }

    /**
     * @param  list<array{entity: string, model: class-string, id: string}>  $manifest
     */
    private function findOrCreateCounterparty(
        ?string $tenantId,
        string $name,
        ?string $taxId,
        string $type,
        array &$manifest,
        string $country = 'IT',
    ): Contact {
        if ($taxId !== null && $taxId !== '') {
            $existing = Contact::query()
                ->where(static function ($q) use ($taxId): void {
                    $q->where('vat_number', $taxId)->orWhere('uid_number', $taxId);
                })
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $created = Contact::query()->create([
            'tenant_id' => $tenantId,
            'type' => $type,
            'legal_type' => 'company',
            'country' => strtoupper($country),
            'company_name' => $name !== '' ? $name : ($taxId ?: 'Migrated Counterparty'),
            'vat_number' => $taxId !== null && ! str_starts_with(strtoupper($taxId), 'CHE') ? $taxId : null,
            'uid_number' => $taxId !== null && str_starts_with(strtoupper($taxId), 'CHE') ? $taxId : null,
        ]);

        $manifest[] = ['entity' => $type === 'supplier' ? MigrationTargetEntity::Vendors->value : MigrationTargetEntity::Customers->value, 'model' => Contact::class, 'id' => $created->id];

        return $created;
    }
}
