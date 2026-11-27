<?php

declare(strict_types=1);

namespace Modules\DownPayments\Http\Resources;

use Illuminate\Http\Request;
use Modules\DownPayments\Models\DownPaymentChain;
use Modules\DownPayments\Models\DownPaymentInstallment;
use Modules\DownPayments\Models\DownPaymentLink;
use Modules\DownPayments\Models\DownPaymentLinkVatLine;
use Modules\DownPayments\Models\EarnestAndSecurityDeposit;
use Modules\DownPayments\Models\FinalSettlementDeductionLine;

/**
 * The full graph of a chain: the plan, the links with their VAT breakdown, the deposits, the deduction lines of the final invoice,
 * the multi-VAT analytical matrix and the journal entries the documents posted.
 *
 * @mixin DownPaymentChain
 */
final class ChainDetailResource extends ChainResource
{
    /**
     * @param  list<array<string, string>>  $matrix
     * @param  list<array{source: string, document_id: string, document_number: string|null, journal_entry_id: string}>  $journalEntries
     */
    public function __construct(DownPaymentChain $resource, private readonly array $matrix, private readonly array $journalEntries)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'installments' => $this->installments->map(static fn (DownPaymentInstallment $row): array => [
                'id' => (string) $row->id,
                'sequence' => (int) $row->sequence,
                'milestone_label' => $row->milestone_label,
                'trigger_type' => $row->trigger_type,
                'due_date' => $row->due_date?->toDateString(),
                'calculation_mode' => $row->calculation_mode,
                'percentage' => $row->percentage !== null ? (string) $row->percentage : null,
                'amount_net' => (string) $row->amount_net,
                'amount_vat' => (string) $row->amount_vat,
                'amount_gross' => (string) $row->amount_gross,
                'instrument_type' => $row->instrument_type,
                'status' => $row->status,
                'vat_breakdown' => $row->vat_breakdown ?? [],
                'linked_document_id' => $row->linked_document_id,
                'linked_invoice_id' => $row->linked_invoice_id,
            ])->all(),
            'links' => $this->links->map(static fn (DownPaymentLink $link): array => [
                'id' => (string) $link->id,
                'installment_id' => $link->installment_id,
                'link_type' => $link->link_type,
                'document_id' => $link->document_id,
                'document_number' => $link->document_number,
                'document_date' => $link->document_date?->toDateString(),
                'parent_link_id' => $link->parent_link_id,
                'status' => $link->status,
                'net_amount' => (string) $link->net_amount,
                'vat_amount' => (string) $link->vat_amount,
                'gross_amount' => (string) $link->gross_amount,
                'credited_gross_amount' => (string) $link->credited_gross_amount,
                'settled_gross_amount' => (string) $link->settled_gross_amount,
                'vat_lines' => $link->vatLines->map(static fn (DownPaymentLinkVatLine $line): array => [
                    'id' => (string) $line->id,
                    'vat_rate_id' => $line->vat_rate_id,
                    'vat_code' => $line->vat_code,
                    'vat_percentage' => (string) $line->vat_percentage,
                    'net_amount' => (string) $line->net_amount,
                    'vat_amount' => (string) $line->vat_amount,
                    'gross_amount' => (string) $line->gross_amount,
                    'settled_net_amount' => (string) $line->settled_net_amount,
                    'settled_vat_amount' => (string) $line->settled_vat_amount,
                    'settled_gross_amount' => (string) $line->settled_gross_amount,
                    'residual_net_amount' => (string) $line->residual_net_amount,
                    'residual_vat_amount' => (string) $line->residual_vat_amount,
                    'residual_gross_amount' => (string) $line->residual_gross_amount,
                    'credited_net_amount' => (string) $line->credited_net_amount,
                    'credited_vat_amount' => (string) $line->credited_vat_amount,
                    'credited_gross_amount' => (string) $line->credited_gross_amount,
                ])->all(),
            ])->all(),
            'deposits' => $this->deposits->map(static fn (EarnestAndSecurityDeposit $deposit): array => (new DepositResource($deposit))->resolve())->all(),
            'deduction_lines' => $this->deductionLines->map(static fn (FinalSettlementDeductionLine $line): array => [
                'id' => (string) $line->id,
                'final_invoice_id' => (string) $line->final_invoice_id,
                'referenced_link_id' => $line->referenced_link_id,
                'referenced_invoice_number' => $line->referenced_invoice_number,
                'referenced_invoice_date' => $line->referenced_invoice_date?->toDateString(),
                'vat_percentage' => (string) $line->vat_percentage,
                'deducted_net' => (string) $line->deducted_net,
                'deducted_vat' => (string) $line->deducted_vat,
                'deducted_gross' => (string) $line->deducted_gross,
            ])->all(),
            'vat_matrix' => $this->matrix,
            'journal_entries' => $this->journalEntries,
        ];
    }
}
