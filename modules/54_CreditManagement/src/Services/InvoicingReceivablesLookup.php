<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Contact;
use App\Services\Sales\DocumentTotalsCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Contracts\CustomerCreditServiceInterface;
use Modules\Invoicing\Models\CustomerCreditProfile;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Invoicing\Models\SalesOrder;

/**
 * Reads receivables, orders and the credit limit from the invoicing module (05_Invoicing), with the same rules its own
 * credit check applies: open receivables are issued invoices still unpaid, and committed orders are the unbilled share
 * of the confirmed ones. It only reads, except for the approved credit limit that goes back through the invoicing
 * credit service.
 */
final readonly class InvoicingReceivablesLookup implements ReceivablesLookupInterface
{
    public function __construct(private CustomerCreditServiceInterface $customerCredit) {}

    public function customerName(string $tenantId, string $customerId): ?string
    {
        return $this->customerNames($tenantId, [$customerId])[$customerId] ?? null;
    }

    public function customerNames(string $tenantId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $names = [];

        foreach (Contact::query()->where('tenant_id', $tenantId)->whereIn('id', array_values(array_unique($customerIds)))->get() as $contact) {
            $name = trim((string) $contact->company_name) !== '' ? (string) $contact->company_name : trim($contact->first_name.' '.$contact->last_name);
            $names[(string) $contact->id] = $name !== '' ? $name : '-';
        }

        return $names;
    }

    public function openInvoices(string $tenantId, ?string $customerId = null): array
    {
        $rows = [];

        $invoices = $this->openInvoiceQuery($tenantId)
            ->when($customerId !== null, static fn ($query) => $query->where('contact_id', $customerId))
            ->orderBy('due_date')
            ->get(['id', 'contact_id', 'invoice_number', 'date', 'due_date', 'currency', 'total_gross', 'total_paid']);

        foreach ($invoices as $invoice) {
            $outstanding = Decimal::sub(DocumentTotalsCalculator::decimal($invoice->total_gross), DocumentTotalsCalculator::decimal($invoice->total_paid));

            if (! Decimal::isPositive($outstanding)) {
                continue;
            }

            $rows[] = [
                'invoice_id' => (string) $invoice->id,
                'customer_id' => (string) $invoice->contact_id,
                'invoice_number' => (string) $invoice->invoice_number,
                'issued_on' => $invoice->date->toDateString(),
                'due_on' => $invoice->due_date?->toDateString(),
                'currency' => (string) $invoice->currency,
                'gross' => Decimal::round2(DocumentTotalsCalculator::decimal($invoice->total_gross)),
                'outstanding' => Decimal::round2($outstanding),
            ];
        }

        return $rows;
    }

    public function committedOrders(string $tenantId, string $customerId): string
    {
        $total = '0';
        $orders = SalesOrder::query()
            ->with('items')
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $customerId)
            ->whereIn('status', [SalesOrderStatus::Confirmed->value, SalesOrderStatus::Approved->value, SalesOrderStatus::OnHold->value, SalesOrderStatus::InProduction->value, SalesOrderStatus::InFulfillment->value, SalesOrderStatus::Shipped->value])
            ->where('billing_status', '!=', 'billed')
            ->get();

        foreach ($orders as $order) {
            foreach ($order->items as $item) {
                $quantity = DocumentTotalsCalculator::decimal($item->quantity);
                $open = Decimal::sub($quantity, DocumentTotalsCalculator::decimal($item->quantity_invoiced));

                if (! Decimal::isPositive($open) || ! Decimal::isPositive($quantity)) {
                    continue;
                }

                $net = Decimal::div(Decimal::mul(DocumentTotalsCalculator::decimal($item->line_total), $open), $quantity);
                $total = Decimal::add($total, Decimal::mul($net, Decimal::add('1', Decimal::div(DocumentTotalsCalculator::decimal($item->tax_rate), '100'))));
            }
        }

        return Decimal::round2($total);
    }

    public function invoicedBetween(string $tenantId, ?string $customerId, string $from, string $to): string
    {
        $total = '0';

        $invoices = $this->issuedInvoiceQuery($tenantId)
            ->when($customerId !== null, static fn ($query) => $query->where('contact_id', $customerId))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get(['total_gross']);

        foreach ($invoices as $invoice) {
            $total = Decimal::add($total, DocumentTotalsCalculator::decimal($invoice->total_gross));
        }

        return Decimal::round2($total);
    }

    public function firstInvoiceDate(string $tenantId, string $customerId): ?string
    {
        $date = $this->issuedInvoiceQuery($tenantId)->where('contact_id', $customerId)->min('date');

        return $date === null ? null : substr((string) $date, 0, 10);
    }

    public function creditLimit(string $tenantId, string $customerId): string
    {
        $profile = CustomerCreditProfile::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->first();

        return $profile === null ? '0.00' : Decimal::round2(DocumentTotalsCalculator::decimal($profile->credit_limit));
    }

    public function applyCreditLimit(string $tenantId, string $customerId, string $limit, string $userId): void
    {
        $this->customerCredit->updateCreditLimit($customerId, $limit, Str::isUuid($userId) ? $userId : '', $tenantId);
    }

    /**
     * @return Builder<SalesInvoice>
     */
    private function issuedInvoiceQuery(string $tenantId): Builder
    {
        return SalesInvoice::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::Posted->value])
            ->whereNotIn('invoice_type', ['proforma', 'credit_note']);
    }

    /**
     * @return Builder<SalesInvoice>
     */
    private function openInvoiceQuery(string $tenantId): Builder
    {
        return $this->issuedInvoiceQuery($tenantId)->whereIn('payment_status', ['unpaid', 'partially_paid']);
    }
}
