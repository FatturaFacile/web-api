<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\AccountsPayable\Models\ApPaymentRun;
use Modules\AccountsPayable\Models\ApPaymentRunItem;
use Modules\AccountsPayable\Models\ApPaymentRunOffset;
use Modules\AccountsPayable\Models\ApPaymentRunTransfer;

/**
 * A payment run (distinta di pagamento) with its totals; once loaded, its items, the transfers grouped by creditor IBAN and
 * the credit-note offsets.
 *
 * @mixin ApPaymentRun
 */
final class PaymentRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_number' => $this->run_number,
            'status' => $this->status,
            'currency' => $this->currency,
            'cutoff_date' => $this->cutoff_date->toDateString(),
            'execution_date' => $this->execution_date->toDateString(),
            'bank_account_id' => $this->bank_account_id,
            'include_skonto' => $this->include_skonto,
            'net_credit_notes' => $this->net_credit_notes,
            'totals' => [
                'debit_total' => (string) $this->debit_total,
                'credit_netted_total' => (string) $this->credit_netted_total,
                'skonto_total' => (string) $this->skonto_total,
                'net_total' => (string) $this->net_total,
                'items_count' => $this->items_count,
                'transfers_count' => $this->transfers_count,
            ],
            'excluded' => $this->excluded ?? [],
            'notes' => $this->notes,
            'workflow' => [
                'created_by' => $this->created_by,
                'submitted_by' => $this->submitted_by,
                'submitted_at' => $this->submitted_at?->toIso8601String(),
                'approved_by' => $this->approved_by,
                'approved_at' => $this->approved_at?->toIso8601String(),
                'rejected_by' => $this->rejected_by,
                'rejected_at' => $this->rejected_at?->toIso8601String(),
                'rejection_reason' => $this->rejection_reason,
                'executed_by' => $this->executed_by,
                'executed_at' => $this->executed_at?->toIso8601String(),
            ],
            'items' => $this->whenLoaded('items', fn (): array => $this->items->map(static fn (ApPaymentRunItem $item): array => [
                'id' => $item->id,
                'open_item_id' => $item->open_item_id,
                'transfer_id' => $item->transfer_id,
                'supplier_id' => $item->supplier_id,
                'document_number' => $item->getAttribute('document_number'),
                'item_status' => $item->getAttribute('item_status'),
                'kind' => $item->kind,
                'selection_reason' => $item->selection_reason,
                'open_amount' => (string) $item->open_amount,
                'skonto_percentage' => $item->skonto_percentage === null ? null : (string) $item->skonto_percentage,
                'skonto_deadline' => $item->skonto_deadline?->toDateString(),
                'skonto_amount' => (string) $item->skonto_amount,
                'credit_applied_amount' => (string) $item->credit_applied_amount,
                'cash_amount' => (string) $item->cash_amount,
                'credit_used_amount' => (string) $item->credit_used_amount,
            ])->all()),
            'transfers' => $this->whenLoaded('transfers', fn (): array => $this->transfers->map(static fn (ApPaymentRunTransfer $transfer): array => [
                'id' => $transfer->id,
                'supplier_id' => $transfer->supplier_id,
                'creditor_iban' => $transfer->creditor_iban,
                'currency' => $transfer->currency,
                'gross_amount' => (string) $transfer->gross_amount,
                'skonto_amount' => (string) $transfer->skonto_amount,
                'credit_netted_amount' => (string) $transfer->credit_netted_amount,
                'amount' => (string) $transfer->amount,
                'items_count' => $transfer->items_count,
                'reference' => $transfer->reference,
                'status' => $transfer->status,
            ])->all()),
            'offsets' => $this->whenLoaded('offsets', fn (): array => $this->offsets->map(static fn (ApPaymentRunOffset $offset): array => [
                'debit_item_id' => $offset->debit_item_id,
                'credit_item_id' => $offset->credit_item_id,
                'amount' => (string) $offset->amount,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
