<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Application\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\SalesBoundedContext\Domain\Enums\BStockConditionGrade;
use Modules\SalesBoundedContext\Domain\ValueObjects\DecimalMoney;
use Modules\SalesBoundedContext\Events\ReverseLogisticsReturnProcessedEvent;
use Modules\SalesBoundedContext\Models\SbcDdtInvoice;
use Modules\SalesBoundedContext\Models\SbcReverseLogisticsReturn;

final readonly class ProcessReverseLogisticsReturnHandler
{
    public function handle(ProcessReverseLogisticsReturnCommand $command): SbcReverseLogisticsReturn
    {
        $data = $command->payload;
        $currency = strtoupper((string) ($data['currency'] ?? 'EUR'));
        $grade = BStockConditionGrade::from((string) ($data['b_stock_grade'] ?? 'grade_b_refurbished'));

        $disposition = (string) ($data['inventory_disposition'] ?? match ($grade) {
            BStockConditionGrade::GradeALikeNew => 'restock_a',
            BStockConditionGrade::GradeBRefurbished => 'b_stock_outlet',
            BStockConditionGrade::GradeCSalvage => 'supplier_claim',
            BStockConditionGrade::ScrapWriteoff => 'scrap',
        });

        $invoiceDate = isset($data['original_invoice_date'])
            ? CarbonImmutable::parse((string) $data['original_invoice_date'])
            : CarbonImmutable::now()->subDays(45);
        $daysSinceInvoice = max(0, (int) $invoiceDate->diffInDays(CarbonImmutable::now()));
        // Art. 26 DPR 633/72: VAT recovery within 1 year (365 days) for unilateral/contractual variations
        $art26VatRecoverable = $daysSinceInvoice <= 365;
        $art26Note = $art26VatRecoverable
            ? 'Art. 26 c.2 DPR 633/72 compliant: VAT recovery permitted (<= 365 days).'
            : 'Art. 26 c.3 DPR 633/72 limit (> 365 days): Net-only commercial credit note without VAT recovery.';

        $net = DecimalMoney::of((float) ($data['return_net_amount'] ?? 1200), $currency);
        $vatRate = $art26VatRecoverable ? (float) ($data['vat_rate_pct'] ?? 22.0) : 0.0;
        $vat = $net->percentage($vatRate);
        $gross = $net->add($vat);

        $isCrossShipping = (bool) ($data['is_advance_cross_shipping'] ?? false);

        $returnRecord = DB::transaction(function () use (
            $command,
            $data,
            $grade,
            $disposition,
            $invoiceDate,
            $daysSinceInvoice,
            $art26VatRecoverable,
            $art26Note,
            $net,
            $vat,
            $gross,
            $currency,
            $isCrossShipping,
        ): SbcReverseLogisticsReturn {
            $creditNote = SbcDdtInvoice::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'related_document_id' => $data['original_invoice_id'] ?? null,
                'document_kind' => 'credit_note',
                'document_number' => 'NC-RMA-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 6)),
                'sdi_doc_type' => 'TD04',
                'currency' => $currency,
                'total_net' => $net->roundHalfUp(),
                'total_tax' => $vat->roundHalfUp(),
                'total_gross' => $gross->roundHalfUp(),
                'issue_date' => now()->toDateString(),
                'status' => 'issued',
            ]);

            return SbcReverseLogisticsReturn::query()->create([
                'tenant_id' => $command->tenantId,
                'dossier_id' => $data['dossier_id'] ?? null,
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'credit_note_document_id' => $creditNote->id,
                'rma_number' => (string) ($data['rma_number'] ?? 'RMA-'.strtoupper(substr(md5((string) now()->timestamp.mt_rand()), 0, 6))),
                'return_reason' => (string) ($data['return_reason'] ?? 'Defective unit / B-stock inspection'),
                'is_advance_cross_shipping' => $isCrossShipping,
                'replacement_shipment_ref' => $isCrossShipping
                    ? (string) ($data['replacement_shipment_ref'] ?? 'XSHIP-'.strtoupper(substr(md5((string) now()->timestamp), 0, 6)))
                    : null,
                'b_stock_grade' => $grade,
                'inventory_disposition' => $disposition,
                'original_invoice_date' => $invoiceDate->toDateString(),
                'days_since_invoice' => $daysSinceInvoice,
                'art26_dpr633_72_vat_recoverable' => $art26VatRecoverable,
                'art26_compliance_note' => $art26Note,
                'return_net_amount' => $net->roundHalfUp(),
                'return_vat_amount' => $vat->roundHalfUp(),
                'credit_note_gross_amount' => $gross->roundHalfUp(),
                'currency' => $currency,
                'status' => 'credit_note_issued',
            ]);
        });

        ReverseLogisticsReturnProcessedEvent::dispatch(
            $command->tenantId,
            (string) $returnRecord->id,
            $grade->value,
            $art26VatRecoverable,
            $returnRecord->credit_note_document_id !== null ? (string) $returnRecord->credit_note_document_id : null,
        );

        return $returnRecord;
    }
}
