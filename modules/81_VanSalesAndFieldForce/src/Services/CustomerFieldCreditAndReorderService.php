<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\VanSalesAndFieldForce\Events\CustomerCreditUnblockedInFieldEvent;
use Modules\VanSalesAndFieldForce\Exceptions\VanSellingException;

/**
 * Evaluates customer credit limits & overdue invoices in the field, supports contextual
 * unblocking upon collecting an overdue invoice, and computes "Riordino Veloce Ultimi 5 Acquisti".
 */
final class CustomerFieldCreditAndReorderService
{
    /**
     * @return array<string, mixed>
     */
    public function getCustomerFieldDossier(string $tenantId, string $contactId, float $proposedSaleAmount = 0.0): array
    {
        $contact = Contact::query()->where('tenant_id', $tenantId)->findOrFail($contactId);
        $credit = $this->evaluateCredit($tenantId, $contact, $proposedSaleAmount);
        $fastReorder = $this->buildFastReorderLast5($tenantId, $contactId);

        return [
            'contact' => [
                'id' => (string) $contact->id,
                'name' => $contact->company_name ?: trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')),
                'country' => $contact->country,
                'vat_number' => $contact->vat_number,
                'uid_number' => $contact->uid_number,
                'sdi_code' => $contact->sdi_code,
            ],
            'credit' => $credit,
            'fast_reorder_last_5' => $fastReorder,
        ];
    }

    /**
     * @return array{
     *   credit_limit: float,
     *   open_exposure: float,
     *   overdue_amount: float,
     *   overdue_invoices: list<array<string, mixed>>,
     *   is_blocked: bool,
     *   block_reason: ?string,
     *   can_unblock_by_collection: bool
     * }
     */
    public function evaluateCredit(string $tenantId, Contact $contact, float $proposedSaleAmount = 0.0): array
    {
        $creditLimit = $this->resolveCreditLimit($tenantId, (string) $contact->id);

        $openInvoices = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contact->id)
            ->where('invoice_type', '!=', 'credit_note')
            ->where('invoice_type', '!=', 'proforma')
            ->whereRaw('(COALESCE(total_gross, 0) - COALESCE(total_paid, 0)) > 0.01')
            ->orderBy('due_date')
            ->get();

        $openExposure = 0.0;
        $overdueAmount = 0.0;
        $overdueList = [];
        $today = now()->toDateString();

        foreach ($openInvoices as $inv) {
            $remaining = round((float) $inv->total_gross - (float) $inv->total_paid, 2);
            if ($remaining <= 0.01) {
                continue;
            }
            $openExposure += $remaining;
            $isOverdue = ($inv->getRawOriginal('payment_status') === 'overdue') || ($inv->due_date->toDateString() < $today);
            if ($isOverdue) {
                $overdueAmount += $remaining;
                $overdueList[] = [
                    'invoice_id' => (string) $inv->id,
                    'invoice_number' => (string) $inv->invoice_number,
                    'date' => $inv->date->toDateString(),
                    'due_date' => $inv->due_date->toDateString(),
                    'total_gross' => round((float) $inv->total_gross, 2),
                    'total_paid' => round((float) $inv->total_paid, 2),
                    'remaining_due' => $remaining,
                    'currency' => $inv->currency ?? 'EUR',
                ];
            }
        }

        $openExposure = round($openExposure, 2);
        $overdueAmount = round($overdueAmount, 2);
        $exceedsLimit = $creditLimit > 0 && ($openExposure + $proposedSaleAmount) > $creditLimit;
        $hasOverdue = $overdueAmount > 0.01;
        $isBlocked = $exceedsLimit || $hasOverdue;

        $blockReason = null;
        if ($hasOverdue && $exceedsLimit) {
            $blockReason = 'overdue_and_limit_exceeded';
        } elseif ($hasOverdue) {
            $blockReason = 'overdue_invoices_present';
        } elseif ($exceedsLimit) {
            $blockReason = 'credit_limit_exceeded';
        }

        return [
            'credit_limit' => $creditLimit,
            'open_exposure' => $openExposure,
            'overdue_amount' => $overdueAmount,
            'overdue_invoices' => $overdueList,
            'is_blocked' => $isBlocked,
            'block_reason' => $blockReason,
            'can_unblock_by_collection' => $hasOverdue || $isBlocked,
        ];
    }

    /**
     * Collects an overdue invoice on the spot to contextually unblock the customer for van unloading.
     *
     * @return array<string, mixed>
     */
    public function unblockWithCollection(
        string $tenantId,
        string $contactId,
        string $invoiceId,
        float $amount,
        string $paymentMethod,
        ?string $reference = null,
        ?string $notes = null,
    ): array {
        if ($amount <= 0) {
            throw new VanSellingException('collection_amount_positive', 422);
        }

        return DB::transaction(function () use ($tenantId, $contactId, $invoiceId, $amount, $paymentMethod, $reference, $notes): array {
            $contact = Contact::query()->where('tenant_id', $tenantId)->findOrFail($contactId);
            $invoice = Invoice::query()
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contact->id)
                ->findOrFail($invoiceId);

            $remaining = round((float) $invoice->total_gross - (float) $invoice->total_paid, 2);
            $applied = round(min($amount, max($remaining, $amount)), 2);

            $payment = Payment::create([
                'tenant_id' => $tenantId,
                'invoice_id' => $invoice->id,
                'amount' => $applied,
                'paid_at' => now()->toDateString(),
                'payment_method' => $paymentMethod,
                'reference' => $reference ?? ('VAN-COL-'.now()->format('YmdHis')),
                'notes' => $notes ?? 'In-field overdue collection for credit unblock (Mod 233)',
                'type' => 'receipt',
            ]);

            $newPaid = round((float) $invoice->total_paid + $applied, 2);
            $isFullyPaid = $newPaid >= round((float) $invoice->total_gross, 2) - 0.01;
            $invoice->total_paid = $newPaid;
            $invoice->payment_status = $isFullyPaid ? 'paid' : 'partial';
            $invoice->save();

            event(new CustomerCreditUnblockedInFieldEvent(
                paymentId: (string) $payment->id,
                tenantId: $tenantId,
                contactId: (string) $contact->id,
                invoiceId: (string) $invoice->id,
                collectedAmount: $applied,
                paymentMethod: $paymentMethod,
            ));

            $updatedCredit = $this->evaluateCredit($tenantId, $contact);

            return [
                'payment_id' => (string) $payment->id,
                'invoice_id' => (string) $invoice->id,
                'invoice_number' => (string) $invoice->invoice_number,
                'collected_amount' => $applied,
                'payment_method' => $paymentMethod,
                'credit' => $updatedCredit,
                'unblocked' => ! $updatedCredit['is_blocked'],
            ];
        });
    }

    /**
     * "Riordino Veloce Ultimi 5 Acquisti": aggregates habitual products from the customer's last 5 invoices.
     *
     * @return list<array<string, mixed>>
     */
    public function buildFastReorderLast5(string $tenantId, string $contactId): array
    {
        $recentInvoiceIds = Invoice::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->where('invoice_type', '!=', 'credit_note')
            ->orderByDesc('date')
            ->limit(5)
            ->pluck('id');

        if ($recentInvoiceIds->isEmpty()) {
            return [];
        }

        $items = InvoiceItem::query()
            ->whereIn('invoice_id', $recentInvoiceIds)
            ->whereNotNull('product_id')
            ->get();

        $products = Product::query()
            ->whereIn('id', $items->pluck('product_id')->filter()->unique()->values()->all())
            ->get()
            ->keyBy('id');

        $grouped = [];
        foreach ($items as $item) {
            $pid = (string) $item->product_id;
            $product = $products->get($pid);
            if (! isset($grouped[$pid])) {
                $grouped[$pid] = [
                    'product_id' => $pid,
                    'sku' => $product !== null ? (string) $product->sku : '',
                    'description' => $item->description ?: ($product !== null ? (string) $product->name : ''),
                    'unit_measure' => $product !== null ? ($product->unit_measure ?? 'pz') : 'pz',
                    'last_unit_price' => round((float) $item->unit_price, 4),
                    'tax_rate' => round((float) $item->tax_rate, 2),
                    'occurrences' => 0,
                    'total_quantity' => 0.0,
                ];
            }
            $grouped[$pid]['occurrences']++;
            $grouped[$pid]['total_quantity'] += (float) $item->quantity;
        }

        $result = [];
        foreach ($grouped as $row) {
            $avgQty = round($row['total_quantity'] / max(1, $row['occurrences']), 2);
            $result[] = [
                'product_id' => $row['product_id'],
                'sku' => $row['sku'],
                'description' => $row['description'],
                'unit_measure' => $row['unit_measure'],
                'suggested_quantity' => $avgQty > 0 ? $avgQty : 1.0,
                'last_unit_price' => $row['last_unit_price'],
                'tax_rate' => $row['tax_rate'],
                'purchase_frequency_last_5' => $row['occurrences'],
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['purchase_frequency_last_5'] <=> $a['purchase_frequency_last_5']);

        return array_slice($result, 0, 15);
    }

    private function resolveCreditLimit(string $tenantId, string $contactId): float
    {
        if (Schema::hasTable('credit_exposures')) {
            $limit = DB::table('credit_exposures')
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contactId)
                ->value('credit_limit');
            if ($limit !== null) {
                return round((float) $limit, 2);
            }
        }

        if (Schema::hasTable('credit_limits')) {
            $limit = DB::table('credit_limits')
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contactId)
                ->value('approved_limit');
            if ($limit !== null) {
                return round((float) $limit, 2);
            }
        }

        // Default conservative B2B field-sales limit when no explicit credit dossier exists
        return 5000.00;
    }
}
