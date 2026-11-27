<?php

declare(strict_types=1);

namespace Modules\Returns\Services;

use App\Models\Contact;
use App\Models\Product;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Returns\Models\AdvanceReplacement;
use Modules\Returns\Models\CustomerReturn;
use Modules\Returns\Models\RefurbishedItem;
use Modules\Returns\Models\ReturnsSetting;
use Modules\Returns\Support\Decimal;
use Modules\Returns\Support\ReturnsError;

/**
 * Advance Replacement / Cross-Shipping Service (Mod 232):
 * Ships a new or refurbished replacement unit immediately (zero-charge cross-shipping) while opening a
 * Pending Defective Return commitment with an N-day SLA window, and automatically bills overdue units.
 */
final readonly class AdvanceReplacementService
{
    public function __construct(
        private ReturnsNumbering $numbering,
        private ReturnsSettingsService $settings,
        private ReturnsStockBooker $stock,
        private ReturnsLedger $ledger,
        private InvoicingServiceInterface $invoicing,
    ) {}

    /**
     * Create an immediate cross-shipping replacement order.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data, ?string $userId): AdvanceReplacement
    {
        return DB::transaction(function () use ($tenantId, $data, $userId): AdvanceReplacement {
            $setting = $this->settings->settings($tenantId);
            $contact = Contact::query()->where('tenant_id', $tenantId)->whereKey($data['contact_id'])->first();
            if ($contact === null) {
                throw ReturnsError::on('contact_id', 'rma.customer_unknown');
            }

            $faultyProduct = Product::withTrashed()->where('tenant_id', $tenantId)->whereKey($data['faulty_product_id'])->first();
            if ($faultyProduct === null) {
                throw ReturnsError::on('faulty_product_id', 'warranty.product_unknown');
            }

            $replacementProductId = (string) ($data['replacement_product_id'] ?? $faultyProduct->id);
            $replacementProduct = Product::withTrashed()->where('tenant_id', $tenantId)->whereKey($replacementProductId)->first();
            if ($replacementProduct === null) {
                throw ReturnsError::on('replacement_product_id', 'warranty.product_unknown');
            }

            $unitType = (string) ($data['replacement_unit_type'] ?? AdvanceReplacement::UnitNew);
            $refurbishedItem = null;
            if (! empty($data['refurbished_item_id'])) {
                $refurbishedItem = RefurbishedItem::query()->where('tenant_id', $tenantId)->whereKey($data['refurbished_item_id'])->first();
                if ($refurbishedItem === null) {
                    throw ReturnsError::on('refurbished_item_id', 'advance_replacement.refurbished_not_found');
                }
            }

            $quantity = Decimal::round($data['quantity'] ?? 1, 4);
            if (! Decimal::isPositive($quantity)) {
                throw ReturnsError::on('quantity', 'quantity_positive');
            }

            $shippedOn = Carbon::parse((string) ($data['shipped_on'] ?? now()->toDateString()));
            $slaDays = (int) ($data['sla_days'] ?? $setting->advance_replacement_sla_days ?: ReturnsSetting::DefaultAdvanceReplacementSlaDays);
            $returnDueOn = $shippedOn->copy()->addDays($slaDays);

            $replacementNumber = $this->numbering->next(AdvanceReplacement::class, 'replacement_number', 'XSHP', $tenantId);
            $warehouse = $this->stock->warehouse($tenantId, $data['warehouse_id'] ?? null);

            $penaltyAmount = Decimal::round(
                $data['penalty_amount'] ?? Decimal::mul($quantity, (string) ($replacementProduct->sale_price ?? '0')),
                2
            );

            $replacement = AdvanceReplacement::create([
                'tenant_id' => $tenantId,
                'replacement_number' => $replacementNumber,
                'customer_return_id' => $data['customer_return_id'] ?? null,
                'rma_id' => $data['rma_id'] ?? null,
                'contact_id' => $contact->id,
                'original_invoice_id' => $data['original_invoice_id'] ?? null,
                'faulty_product_id' => $faultyProduct->id,
                'faulty_serial_number' => $data['faulty_serial_number'] ?? null,
                'replacement_product_id' => $replacementProduct->id,
                'replacement_serial_number' => $data['replacement_serial_number'] ?? null,
                'replacement_unit_type' => $unitType,
                'refurbished_item_id' => $refurbishedItem?->id,
                'quantity' => $quantity,
                'warehouse_id' => $warehouse->id,
                'status' => AdvanceReplacement::StatusPendingDefectiveReturn,
                'shipped_on' => $shippedOn->toDateString(),
                'sla_days' => $slaDays,
                'return_due_on' => $returnDueOn->toDateString(),
                'penalty_amount' => $penaltyAmount,
                'currency' => (string) ($data['currency'] ?? 'EUR'),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);

            $movementId = $this->stock->issue(
                (string) $replacementProduct->id,
                $quantity,
                $warehouse,
                $replacement,
                __('returns_errors.advance_replacement.outbound_note', ['number' => $replacementNumber])
            );

            $replacement->update(['outbound_stock_movement_id' => $movementId]);

            if ($refurbishedItem !== null) {
                $refurbishedItem->update(['is_available' => false]);
            }

            $this->ledger->record('returns.advance_replacement_created', $replacement, [
                'unit_type' => $unitType,
                'sla_days' => $slaDays,
                'return_due_on' => $returnDueOn->toDateString(),
            ]);

            return $replacement->refresh()->load(['contact', 'customerReturn', 'faultyProduct', 'replacementProduct']);
        });
    }

    /**
     * Mark the faulty unit as returned within (or after) SLA, closing the commitment without penalty.
     */
    public function receiveDefective(AdvanceReplacement $replacement, ?string $notes = null): AdvanceReplacement
    {
        return DB::transaction(function () use ($replacement, $notes): AdvanceReplacement {
            $locked = AdvanceReplacement::query()->whereKey($replacement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AdvanceReplacement::StatusPendingDefectiveReturn) {
                throw ReturnsError::on('status', 'transition', [
                    'from' => $locked->status,
                    'to' => AdvanceReplacement::StatusDefectiveReceived,
                ]);
            }

            $locked->update([
                'status' => AdvanceReplacement::StatusDefectiveReceived,
                'defective_received_at' => now(),
                'notes' => $notes ?? $locked->notes,
            ]);

            if ($locked->customer_return_id !== null) {
                CustomerReturn::query()
                    ->whereKey($locked->customer_return_id)
                    ->whereIn('status', [CustomerReturn::StatusRequested, CustomerReturn::StatusApproved])
                    ->update(['status' => CustomerReturn::StatusReceived, 'received_at' => now()]);
            }

            $this->ledger->record('returns.advance_replacement_defective_received', $locked);

            return $locked->refresh()->load(['contact', 'customerReturn', 'faultyProduct', 'replacementProduct']);
        });
    }

    /**
     * Bill an overdue advance replacement when the defective unit was not returned within N days.
     */
    public function billOverdue(AdvanceReplacement $replacement, ?string $userId, bool $force = false): AdvanceReplacement
    {
        return DB::transaction(function () use ($replacement, $userId, $force): AdvanceReplacement {
            $locked = AdvanceReplacement::query()->with('replacementProduct')->whereKey($replacement->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AdvanceReplacement::StatusPendingDefectiveReturn) {
                throw ReturnsError::on('status', 'transition', [
                    'from' => $locked->status,
                    'to' => AdvanceReplacement::StatusOverdueBilled,
                ]);
            }

            if (! $force && ! $locked->return_due_on->lt(Carbon::today())) {
                throw ReturnsError::on('return_due_on', 'advance_replacement.not_overdue');
            }

            $unitPrice = Decimal::isPositive((string) $locked->penalty_amount)
                ? Decimal::round(Decimal::div((string) $locked->penalty_amount, (string) $locked->quantity), 4)
                : Decimal::round((string) ($locked->replacementProduct->sale_price ?? '0'), 4);

            $taxRate = (string) ($locked->replacementProduct->tax_rate ?? '22.00');
            $description = __('returns_errors.advance_replacement.penalty_invoice_line', [
                'number' => $locked->replacement_number,
                'product' => (string) ($locked->replacementProduct->name ?? 'Replacement unit'),
                'due' => $locked->return_due_on->toDateString(),
            ]);

            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => (string) $locked->tenant_id,
                'contact_id' => (string) $locked->contact_id,
                'invoice_type' => 'standard',
                'document_type' => 'TD01',
                'date' => now()->toDateString(),
                'due_date' => now()->addDays(15)->toDateString(),
                'currency' => $locked->currency,
                'notes' => __('returns_errors.advance_replacement.penalty_invoice_note', ['number' => $locked->replacement_number]),
                'skip_charge_lines' => true,
                'created_by' => $userId,
            ], [
                [
                    'product_id' => (string) $locked->replacement_product_id,
                    'description' => $description,
                    'quantity' => (string) $locked->quantity,
                    'unit_price' => $unitPrice,
                    'tax_rate' => $taxRate,
                ],
            ]);

            $locked->update([
                'status' => AdvanceReplacement::StatusOverdueBilled,
                'penalty_invoice_id' => (string) $invoice->id,
                'penalty_invoice_number' => (string) $invoice->invoice_number,
                'billed_at' => now(),
            ]);

            $this->ledger->record('returns.advance_replacement_overdue_billed', $locked, [
                'penalty_invoice_id' => (string) $invoice->id,
                'penalty_amount' => $locked->penalty_amount,
            ]);

            return $locked->refresh()->load(['contact', 'customerReturn', 'faultyProduct', 'replacementProduct']);
        });
    }

    /**
     * Scan and bill all overdue advance replacements for a tenant.
     */
    public function processOverdueForTenant(string $tenantId, ?string $userId = null): int
    {
        $overdue = AdvanceReplacement::query()
            ->where('tenant_id', $tenantId)
            ->where('status', AdvanceReplacement::StatusPendingDefectiveReturn)
            ->where('return_due_on', '<', now()->toDateString())
            ->get();

        $billedCount = 0;
        foreach ($overdue as $item) {
            $this->billOverdue($item, $userId, false);
            $billedCount++;
        }

        return $billedCount;
    }
}
