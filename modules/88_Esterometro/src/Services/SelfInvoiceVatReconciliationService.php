<?php

declare(strict_types=1);

namespace Modules\Esterometro\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Modules\Esterometro\Exceptions\EsterometroException;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Models\SelfInvoiceSdiLog;
use Modules\Esterometro\Models\SelfInvoiceSetting;
use Modules\GeneralLedger\Contracts\DoubleEntryPostingServiceInterface;
use Modules\GeneralLedger\Enums\PostingAccountRole;
use Modules\GeneralLedger\Services\FiscalPeriodResolver;
use Modules\GeneralLedger\Services\PostingAccountResolver;
use Modules\ItalianVat\Services\VatRegisterService;
use Modules\ItalianVat\Services\VatSectionService;
use Throwable;

/**
 * Dual VAT register reconciliation (VEN-AUT + ACQ-RC) and neutral Prima Nota double-entry posting.
 */
final class SelfInvoiceVatReconciliationService
{
    public function __construct(
        private readonly VatSectionService $vatSections,
        private readonly VatRegisterService $vatRegister,
        private readonly DoubleEntryPostingServiceInterface $glPosting,
        private readonly FiscalPeriodResolver $periodResolver,
        private readonly PostingAccountResolver $accountResolver,
    ) {}

    public function reconcile(SelfInvoice $selfInvoice, ?string $actorUserId = null): SelfInvoice
    {
        if ($selfInvoice->is_vat_reconciled) {
            return $selfInvoice;
        }

        $tenantId = (string) $selfInvoice->tenant_id;
        if ($tenantId === '') {
            throw EsterometroException::vatReconciliationFailed('Missing tenant_id on self-invoice.');
        }

        $selfInvoice->loadMissing('lines');
        $settings = SelfInvoiceSetting::query()->where('tenant_id', $tenantId)->first();

        return DB::transaction(function () use ($selfInvoice, $tenantId, $actorUserId, $settings): SelfInvoice {
            $this->vatSections->ensureDefaults($tenantId);
            $sections = $this->vatSections->list($tenantId);

            $salesCode = $settings?->sales_vat_section_code ?? 'VEN-AUT';
            $purchaseCode = $settings?->purchase_vat_section_code ?? 'ACQ-RC';

            $salesSection = $sections->firstWhere('code', $salesCode)
                ?? $sections->firstWhere('register_type', VatSectionService::SALES);
            $purchaseSection = $sections->firstWhere('code', $purchaseCode)
                ?? $sections->firstWhere('register_type', VatSectionService::PURCHASES);

            if ($salesSection === null || $purchaseSection === null) {
                throw EsterometroException::vatReconciliationFailed('Required VEN-AUT / ACQ-RC VAT sections are missing.');
            }

            $dominantRate = $this->resolveDominantVatRate($selfInvoice);
            $dominantNature = $this->resolveDominantVatNature($selfInvoice);
            $deductionPct = $this->resolveEffectiveDeductibility($selfInvoice);
            $entryDate = $selfInvoice->integration_date->toDateString();

            $salesEntry = $this->vatRegister->record($tenantId, $actorUserId, [
                'section_id' => $salesSection->id,
                'entry_date' => $entryDate,
                'document_date' => $entryDate,
                'document_number' => $selfInvoice->document_number,
                'document_kind' => 'self_invoice',
                'document_type_code' => $selfInvoice->document_type->value,
                'contact_id' => $selfInvoice->supplier_id,
                'counterparty_name' => $selfInvoice->supplier_name,
                'counterparty_vat' => $selfInvoice->supplier_vat_number,
                'taxable_amount' => (float) $selfInvoice->taxable_amount_eur,
                'vat_rate' => $dominantRate,
                'vat_amount' => (float) $selfInvoice->vat_amount_eur,
                'vat_nature' => $dominantNature,
                'deduction_percentage' => 100.0,
                'apply_pro_rata' => false,
                'source_type' => 'self_invoice_sales',
                'source_id' => $selfInvoice->id,
                'notes' => "Esterometro {$selfInvoice->document_type->value} output VAT",
            ]);

            $purchaseEntry = $this->vatRegister->record($tenantId, $actorUserId, [
                'section_id' => $purchaseSection->id,
                'entry_date' => $entryDate,
                'document_date' => $selfInvoice->original_invoice_date?->toDateString() ?? $entryDate,
                'document_number' => $selfInvoice->original_invoice_number ?: $selfInvoice->document_number,
                'document_kind' => 'reverse_charge',
                'document_type_code' => $selfInvoice->document_type->value,
                'contact_id' => $selfInvoice->supplier_id,
                'counterparty_name' => $selfInvoice->supplier_name,
                'counterparty_vat' => $selfInvoice->supplier_vat_number,
                'taxable_amount' => (float) $selfInvoice->taxable_amount_eur,
                'vat_rate' => $dominantRate,
                'vat_amount' => (float) $selfInvoice->vat_amount_eur,
                'vat_nature' => $dominantNature,
                'deduction_percentage' => $deductionPct,
                'apply_pro_rata' => true,
                'source_type' => 'self_invoice_purchase',
                'source_id' => $selfInvoice->id,
                'notes' => "Esterometro {$selfInvoice->document_type->value} input VAT",
            ]);

            $journalEntryId = $this->postNeutralJournalIfConfigured($selfInvoice, $tenantId, $actorUserId, $settings);

            $selfInvoice->forceFill([
                'sales_vat_entry_id' => $salesEntry->id,
                'purchase_vat_entry_id' => $purchaseEntry->id,
                'journal_entry_id' => $journalEntryId,
                'is_vat_reconciled' => true,
            ])->save();

            SelfInvoiceSdiLog::create([
                'tenant_id' => $tenantId,
                'self_invoice_id' => $selfInvoice->id,
                'event_type' => 'VAT_RECONCILED',
                'from_status' => $selfInvoice->status->value,
                'to_status' => $selfInvoice->status->value,
                'raw_payload' => json_encode([
                    'sales_vat_entry_id' => $salesEntry->id,
                    'purchase_vat_entry_id' => $purchaseEntry->id,
                    'journal_entry_id' => $journalEntryId,
                ], JSON_THROW_ON_ERROR),
                'created_by' => $actorUserId,
            ]);

            return $selfInvoice->fresh(['lines', 'sdiLogs']) ?? $selfInvoice;
        });
    }

    private function postNeutralJournalIfConfigured(
        SelfInvoice $selfInvoice,
        string $tenantId,
        ?string $actorUserId,
        ?SelfInvoiceSetting $settings,
    ): ?string {
        if ($settings !== null && ! $settings->auto_post_journal) {
            return null;
        }

        $vatAmount = round((float) $selfInvoice->vat_amount_eur, 2);
        if ($vatAmount <= 0.0) {
            return null;
        }

        try {
            $companyId = Company::query()->where('tenant_id', $tenantId)->value('id');
            if ($companyId === null) {
                return null;
            }

            $postingDate = $selfInvoice->integration_date->toDateString();
            $period = $this->periodResolver->forDate($tenantId, $postingDate);
            if ($period === null) {
                return null;
            }

            $outputVatAccount = $this->accountResolver->account($tenantId, PostingAccountRole::OutputVat);
            $inputVatAccount = $this->accountResolver->account($tenantId, PostingAccountRole::InputVat);
            $expenseAccount = $this->accountResolver->account($tenantId, PostingAccountRole::PurchaseExpense);

            if ($outputVatAccount === null || $inputVatAccount === null) {
                return null;
            }

            $deductible = round((float) $selfInvoice->deductible_vat_eur, 2);
            $nonDeductible = round(max(0.0, $vatAmount - $deductible), 2);

            $lines = [];
            if ($deductible > 0.0) {
                $lines[] = [
                    'account_id' => $inputVatAccount->id,
                    'debit' => $deductible,
                    'credit' => 0.0,
                    'description' => "Reverse charge input VAT ({$selfInvoice->document_number})",
                ];
            }
            if ($nonDeductible > 0.0) {
                $nonDedAccountId = $settings?->non_deductible_account_id ?? $expenseAccount?->id ?? $inputVatAccount->id;
                $lines[] = [
                    'account_id' => $nonDedAccountId,
                    'debit' => $nonDeductible,
                    'credit' => 0.0,
                    'description' => "Non-deductible reverse charge VAT ({$selfInvoice->document_number})",
                ];
            }
            $lines[] = [
                'account_id' => $outputVatAccount->id,
                'debit' => 0.0,
                'credit' => $vatAmount,
                'description' => "Reverse charge output VAT ({$selfInvoice->document_number})",
            ];

            $entry = $this->glPosting->createAndPost([
                'tenant_id' => $tenantId,
                'company_id' => (string) $companyId,
                'fiscal_period_id' => $period->id,
                'entry_number' => 'RC-'.$selfInvoice->document_number,
                'source_document_type' => 'self_invoice',
                'source_document_id' => $selfInvoice->id,
                'document_number' => $selfInvoice->document_number,
                'posting_date' => $postingDate,
                'document_date' => $postingDate,
                'journal_type' => 'vat_reverse_charge',
                'description' => "Reverse charge VAT reconciliation {$selfInvoice->document_type->value} {$selfInvoice->document_number}",
                'lines' => $lines,
            ], $actorUserId);

            return (string) $entry->id;
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveDominantVatRate(SelfInvoice $selfInvoice): float
    {
        $first = $selfInvoice->lines->first();

        return $first !== null ? (float) $first->vat_rate : 22.0;
    }

    private function resolveDominantVatNature(SelfInvoice $selfInvoice): ?string
    {
        foreach ($selfInvoice->lines as $line) {
            if ($line->vat_nature !== null && $line->vat_nature !== '') {
                return $line->vat_nature;
            }
        }

        return null;
    }

    private function resolveEffectiveDeductibility(SelfInvoice $selfInvoice): float
    {
        $vat = (float) $selfInvoice->vat_amount_eur;
        if ($vat <= 0.0) {
            return 100.0;
        }

        return round(((float) $selfInvoice->deductible_vat_eur / $vat) * 100.0, 2);
    }
}
