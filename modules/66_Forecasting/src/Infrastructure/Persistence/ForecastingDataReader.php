<?php

declare(strict_types=1);

namespace Modules\Forecasting\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read-only access to the ledgers of the other modules that feed the forecasts. Every query filters on the tenant
 * explicitly and skips soft-deleted rows; date arithmetic stays in PHP so the same code runs on SQLite and PostgreSQL.
 */
final class ForecastingDataReader
{
    /**
     * Receivables settled in full during the window, with the day the last payment arrived.
     *
     * @return list<array{customer_id: string, due_date: string, settled_at: string}>
     */
    public function settledInvoices(string $tenantId, CarbonImmutable $since): array
    {
        $payments = DB::table('payments')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('type', 'payment')
            ->groupBy('invoice_id')
            ->selectRaw('invoice_id, MAX(paid_at) AS settled_at');

        $rows = DB::table('invoices as i')
            ->joinSub($payments, 'p', 'p.invoice_id', '=', 'i.id')
            ->where('i.tenant_id', $tenantId)
            ->whereNull('i.deleted_at')
            ->where('i.payment_status', 'paid')
            ->where('i.invoice_type', '!=', 'credit_note')
            ->whereNotNull('i.due_date')
            ->where('p.settled_at', '>=', $since->toDateString())
            ->get(['i.contact_id', 'i.due_date', 'p.settled_at']);

        $samples = [];

        foreach ($rows as $row) {
            $samples[] = [
                'customer_id' => (string) $row->contact_id,
                'due_date' => substr((string) $row->due_date, 0, 10),
                'settled_at' => substr((string) $row->settled_at, 0, 10),
            ];
        }

        return $samples;
    }

    /**
     * Issued receivables not yet collected in full.
     *
     * @return list<array{id: string, customer_id: string, invoice_number: string, due_date: string, open_amount: float}>
     */
    public function openReceivables(string $tenantId): array
    {
        $rows = DB::table('invoices')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('payment_status', ['unpaid', 'partially_paid', 'overdue'])
            ->where('invoice_type', '!=', 'credit_note')
            ->where('status', '!=', 'draft')
            ->whereNotNull('due_date')
            ->get(['id', 'contact_id', 'invoice_number', 'due_date', 'total_gross', 'total_paid']);

        $open = [];

        foreach ($rows as $row) {
            $amount = (float) $row->total_gross - (float) $row->total_paid;

            if ($amount > 0.0) {
                $open[] = [
                    'id' => (string) $row->id,
                    'customer_id' => (string) $row->contact_id,
                    'invoice_number' => (string) $row->invoice_number,
                    'due_date' => substr((string) $row->due_date, 0, 10),
                    'open_amount' => $amount,
                ];
            }
        }

        return $open;
    }

    /**
     * Supplier bills not yet paid in full.
     *
     * @return list<array{id: string, supplier_id: string, invoice_number: string, due_date: string, open_amount: float}>
     */
    public function openPayables(string $tenantId): array
    {
        $rows = DB::table('passive_invoices')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereIn('payment_status', ['unpaid', 'partially_paid', 'overdue'])
            ->get(['id', 'supplier_id', 'invoice_number', 'date', 'due_date', 'total_amount', 'total_paid']);

        $open = [];

        foreach ($rows as $row) {
            $amount = (float) $row->total_amount - (float) $row->total_paid;

            if ($amount > 0.0) {
                $open[] = [
                    'id' => (string) $row->id,
                    'supplier_id' => (string) $row->supplier_id,
                    'invoice_number' => (string) $row->invoice_number,
                    'due_date' => substr((string) ($row->due_date ?? $row->date), 0, 10),
                    'open_amount' => $amount,
                ];
            }
        }

        return $open;
    }

    /**
     * Sum of the active bank accounts in the currency.
     */
    public function startingCash(string $tenantId, string $currency): float
    {
        return (float) DB::table('bank_accounts')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('currency', $currency)
            ->sum('current_balance');
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function contactNames(string $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('contacts')->where('tenant_id', $tenantId)->whereIn('id', $ids)->get(['id', 'company_name', 'first_name', 'last_name']) as $row) {
            $names[(string) $row->id] = (string) ($row->company_name ?: trim($row->first_name.' '.$row->last_name));
        }

        return $names;
    }

    /**
     * Sales quantities per product, warehouse and calendar month, from the confirmed sales orders.
     *
     * @return array<string, array<string, array<string, float>>> product id => warehouse id => 'Y-m' => quantity
     */
    public function monthlySales(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?string $productId = null, ?string $warehouseId = null): array
    {
        $rows = $this->salesLines($tenantId, $from, $to, $productId, $warehouseId);
        $sales = [];

        foreach ($rows as $row) {
            $month = substr((string) $row->order_date, 0, 7);
            $sales[(string) $row->product_id][(string) $row->warehouse][$month] = ($sales[(string) $row->product_id][(string) $row->warehouse][$month] ?? 0.0) + (float) $row->quantity;
        }

        return $sales;
    }

    /**
     * Quantity sold of a product in a warehouse between two days, inclusive.
     */
    public function soldQuantity(string $tenantId, string $productId, string $warehouseId, CarbonImmutable $from, CarbonImmutable $to): float
    {
        $total = 0.0;

        foreach ($this->salesLines($tenantId, $from, $to, $productId, $warehouseId) as $row) {
            $total += (float) $row->quantity;
        }

        return $total;
    }

    /**
     * Days between the purchase order and each goods receipt of the product.
     *
     * @return list<int>
     */
    public function supplierLeadTimes(string $tenantId, string $productId, CarbonImmutable $since): array
    {
        $rows = DB::table('goods_receipt_items as gri')
            ->join('goods_receipts as gr', 'gr.id', '=', 'gri.goods_receipt_id')
            ->join('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')
            ->where('gri.tenant_id', $tenantId)
            ->where('gri.product_id', $productId)
            ->whereNull('gri.deleted_at')
            ->whereNull('gr.deleted_at')
            ->whereNull('po.deleted_at')
            ->where('gr.date', '>=', $since->toDateString())
            ->get(['po.date as order_date', 'gr.date as receipt_date']);

        $days = [];

        foreach ($rows as $row) {
            $days[] = max(0, (int) CarbonImmutable::parse((string) $row->order_date)->startOfDay()->diffInDays(CarbonImmutable::parse((string) $row->receipt_date)->startOfDay()));
        }

        return $days;
    }

    /**
     * Net quantity of the product in the warehouse according to the stock movements.
     */
    public function stockOnHand(string $tenantId, string $productId, string $warehouseId): float
    {
        return (float) DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->sum('quantity');
    }

    /**
     * @return array<string, mixed>
     */
    public function payrollTotals(string $tenantId, CarbonImmutable $since): array
    {
        $rows = DB::table('payroll_payslips')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where(static fn ($query) => $query->where('year', '>', (int) $since->format('Y'))->orWhere(static fn ($inner) => $inner->where('year', (int) $since->format('Y'))->where('month', '>=', (int) $since->format('n'))))
            ->get(['year', 'month', 'gross_salary', 'employee_deductions', 'employer_contributions']);

        $months = [];

        foreach ($rows as $row) {
            $key = sprintf('%04d-%02d', $row->year, $row->month);
            $months[$key]['gross'] = ($months[$key]['gross'] ?? 0.0) + (float) $row->gross_salary;
            $months[$key]['contributions'] = ($months[$key]['contributions'] ?? 0.0) + (float) $row->employee_deductions + (float) $row->employer_contributions;
        }

        return $months;
    }

    /**
     * Amounts payable of the latest VAT settlements, newest first.
     *
     * @return list<float>
     */
    public function recentVatPayments(string $tenantId, int $limit = 4): array
    {
        return DB::table('vat_settlements')
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->where('payable_amount', '>', 0)
            ->orderByDesc('year')
            ->orderByDesc('period')
            ->limit($limit)
            ->pluck('payable_amount')
            ->map(static fn ($amount): float => (float) $amount)
            ->all();
    }

    /**
     * @return list<object>
     */
    private function salesLines(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, ?string $productId, ?string $warehouseId): array
    {
        $query = DB::table('sales_order_items as i')
            ->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->where('i.tenant_id', $tenantId)
            ->where('o.tenant_id', $tenantId)
            ->whereNull('i.deleted_at')
            ->whereNull('o.deleted_at')
            ->whereNotIn('o.status', ['draft', 'cancelled', 'rejected'])
            ->whereNotNull('i.product_id')
            ->whereBetween('o.date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('i.product_id AS product_id, COALESCE(i.warehouse_id, o.warehouse_id) AS warehouse, o.date AS order_date, i.quantity - i.quantity_cancelled AS quantity');

        if ($productId !== null) {
            $query->where('i.product_id', $productId);
        }

        if ($warehouseId !== null) {
            $query->whereRaw('COALESCE(i.warehouse_id, o.warehouse_id) = ?', [$warehouseId]);
        }

        return $query->get()->filter(static fn ($row): bool => $row->warehouse !== null)->values()->all();
    }
}
