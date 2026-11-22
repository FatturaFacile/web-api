<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Enums\InvoiceStatus;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;
use Modules\Invoicing\Models\SalesInvoice;

/**
 * Days Sales Outstanding, the countback-free standard formula:
 *
 *   DSO = open receivables at the date / credit sales of the period * days of the period
 *
 * Credit sales are the gross value of the invoices issued in the period (credit notes subtract). The period is 90
 * days unless asked otherwise. With no sales in the period the DSO is undefined (`null`) when something is still
 * owed, and 0 when nothing is.
 */
final class DsoService
{
    public const int DefaultPeriodDays = 90;

    public function __construct(private readonly ReceivableItemService $items) {}

    /**
     * @return array{as_of: string, period_days: int, currency: string, receivables: string, credit_sales: string, dso: string|null}
     */
    public function calculate(string $tenantId, CarbonInterface $today, ?string $customerId = null, int $periodDays = self::DefaultPeriodDays, ?string $currency = null): array
    {
        $periodDays = max(1, min(3660, $periodDays));
        $currency ??= (string) (Tenant::query()->whereKey($tenantId)->value('default_currency') ?: 'EUR');
        $this->items->syncTenant($tenantId, $customerId);

        $receivables = (string) (ReceivableItem::query()
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->when($customerId !== null, static fn ($query) => $query->where('customer_id', $customerId))
            ->where('status', '!=', ReceivableItem::StatusWrittenOff)
            ->where('open_amount', '>', 0)
            ->whereDate('issue_date', '<=', $today->toDateString())
            ->sum('open_amount') ?: '0');

        $from = $today->copy()->subDays($periodDays - 1)->toDateString();
        $sales = SalesInvoice::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('currency', $currency)
            ->when($customerId !== null, static fn ($query) => $query->where('contact_id', $customerId))
            ->whereIn('status', [InvoiceStatus::Approved->value, InvoiceStatus::Posted->value])
            ->where('invoice_type', '!=', 'proforma')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $today->toDateString())
            ->get(['invoice_type', 'total_gross'])
            ->reduce(static fn (string $sum, SalesInvoice $invoice): string => $invoice->invoice_type === 'credit_note'
                ? Decimal::sub($sum, (string) $invoice->total_gross)
                : Decimal::add($sum, (string) $invoice->total_gross), '0');

        $dso = match (true) {
            Decimal::isPositive($sales) => Decimal::round2(Decimal::mul(Decimal::div($receivables, $sales), (string) $periodDays)),
            Decimal::isPositive($receivables) => null,
            default => '0.00',
        };

        return [
            'as_of' => $today->toDateString(),
            'period_days' => $periodDays,
            'currency' => $currency,
            'receivables' => Decimal::round2($receivables),
            'credit_sales' => Decimal::round2(Decimal::isPositive($sales) ? $sales : '0'),
            'dso' => $dso,
        ];
    }
}
