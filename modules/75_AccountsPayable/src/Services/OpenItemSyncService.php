<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Services;

use App\Models\PassiveInvoice;
use App\Models\SupplierPayment;
use Illuminate\Support\Facades\DB;
use Modules\AccountsPayable\Enums\OpenItemKind;
use Modules\AccountsPayable\Enums\OpenItemSource;
use Modules\AccountsPayable\Enums\OpenItemStatus;
use Modules\AccountsPayable\Models\ApOpenItem;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Keeps the supplier open items (`ap_open_items`) in line with the received supplier invoices and credit notes: one
 * item per document, settled by what was paid (payments, cash discounts and credit notes offset). The sync is idempotent;
 * it never touches the dispute and approval state of an item, which belongs to this module. `syncTenant` picks up the
 * documents that changed outside the module (a bank reconciliation, a manual payment) and the new ones.
 */
final class OpenItemSyncService
{
    /**
     * Statuses a sync leaves alone while the item still has a balance.
     *
     * @var list<string>
     */
    private const array KeptStatuses = ['in_proposal', 'authorized', 'disputed', 'blocked'];

    /**
     * Creates or updates the open items of every unsettled received document of the tenant.
     */
    public function syncTenant(string $tenantId, ?string $supplierId = null): void
    {
        $settledLater = ApOpenItem::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('passive_invoice_id')
            ->where('status', '!=', OpenItemStatus::Paid->value)
            ->pluck('passive_invoice_id')
            ->all();

        PassiveInvoice::query()
            ->with('supplier')
            ->where('tenant_id', $tenantId)
            ->when($supplierId !== null, static fn ($query) => $query->where('supplier_id', $supplierId))
            ->where(static function ($query) use ($settledLater): void {
                $query->whereNull('payment_status')->orWhereIn('payment_status', ['unpaid', 'partially_paid'])->orWhereIn('id', $settledLater);
            })
            ->orderBy('id')
            ->chunk(200, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $this->syncInvoice($invoice);
                }
            });
    }

    /**
     * Creates or updates the open item of a received document.
     */
    public function syncInvoice(PassiveInvoice $invoice): ApOpenItem
    {
        return DB::transaction(function () use ($invoice): ApOpenItem {
            $tenantId = (string) $invoice->tenant_id;
            $item = ApOpenItem::query()->where('tenant_id', $tenantId)->where('passive_invoice_id', $invoice->id)->lockForUpdate()->first();
            $original = Decimal::round2(Decimal::abs($invoice->amountDue()));
            $paid = Decimal::round2(Decimal::abs((string) ($invoice->total_paid ?? '0')));
            $paid = Decimal::compare($paid, $original) > 0 ? $original : $paid;
            $open = Decimal::round2(Decimal::sub($original, $paid));
            $discount = Decimal::round2((string) (SupplierPayment::withoutGlobalScopes()
                ->where('passive_invoice_id', $invoice->id)
                ->where('type', 'cash_discount')
                ->sum('amount') ?: '0'));

            $status = match (true) {
                ! Decimal::isPositive($open) => OpenItemStatus::Paid->value,
                $item !== null && in_array($item->status, self::KeptStatuses, true) => (string) $item->status,
                default => OpenItemStatus::Open->value,
            };
            $iban = $this->iban($invoice);
            $attributes = [
                'supplier_id' => (string) $invoice->supplier_id,
                'source' => $this->source($invoice)->value,
                'kind' => $invoice->document_type === 'TD04' ? OpenItemKind::CreditNote->value : OpenItemKind::Invoice->value,
                'document_number' => (string) $invoice->invoice_number,
                'document_date' => $invoice->date->toDateString(),
                'due_date' => ($invoice->due_date ?? $invoice->date)->toDateString(),
                'currency' => (string) ($invoice->currency ?: 'EUR'),
                'original_amount' => $original,
                'paid_amount' => $paid,
                'discount_taken_amount' => $discount,
                'open_amount' => $open,
                'status' => $status,
            ];

            if ($item === null) {
                return ApOpenItem::query()->create($attributes + ['tenant_id' => $tenantId, 'passive_invoice_id' => (string) $invoice->id, 'creditor_iban' => $iban]);
            }

            if ($iban !== null && ! in_array($item->status, ['in_proposal', 'authorized'], true)) {
                $attributes['creditor_iban'] = $iban;
            }

            $item->fill($attributes)->save();

            return $item;
        });
    }

    /**
     * The IBAN to pay: the one of the QR-bill of the invoice, else the IBAN (or QR-IBAN) of the supplier.
     */
    private function iban(PassiveInvoice $invoice): ?string
    {
        $qr = $invoice->qr_raw_payload !== null ? preg_split('/\r\n|\r|\n/', trim((string) $invoice->qr_raw_payload)) : [];
        $iban = ($qr[3] ?? '') ?: ($invoice->supplier?->iban ?: $invoice->supplier?->qr_iban);

        return $iban === null || $iban === '' ? null : strtoupper(str_replace(' ', '', (string) $iban));
    }

    private function source(PassiveInvoice $invoice): OpenItemSource
    {
        return match (true) {
            $invoice->qr_raw_payload !== null => OpenItemSource::SwissQr,
            default => OpenItemSource::ElectronicXml,
        };
    }
}
