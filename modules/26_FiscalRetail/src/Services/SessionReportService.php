<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use App\Models\PosSale;
use App\Models\PosSaleItem;
use App\Models\PosSession;
use Illuminate\Support\Facades\DB;
use Modules\FiscalRetail\Models\CommercialDocument;
use Modules\FiscalRetail\Models\DailyCorrispettivo;
use Modules\FiscalRetail\Models\RetailReturn;
use Modules\FiscalRetail\Models\RetailReturnLine;
use Modules\FiscalRetail\Models\RetailSalePromotion;
use Modules\FiscalRetail\Models\RetailSessionReport;

/**
 * X and Z reports of a POS session. The X report is a reading of an open session and can be produced any number of
 * times; the Z report closes the session: it is numbered per company and year, stored once with the figures as they
 * were, and compares the takings with the commercial documents issued for the session and with the daily
 * corrispettivi of its business day.
 */
final class SessionReportService
{
    public function __construct(private readonly CashDrawerService $drawer, private readonly RetailNumbers $numbers) {}

    /**
     * Totals of a session as of now.
     *
     * @return array<string, mixed>
     */
    public function totals(PosSession $session): array
    {
        $sales = PosSale::query()->where('pos_session_id', $session->id)->get();
        $completed = $sales->where('status', 'completed');
        $saleIds = $completed->pluck('id');
        $returns = RetailReturn::query()->where('pos_session_id', $session->id)->get();
        $drawer = $this->drawer->summary($session);
        $closing = $session->closing_cash !== null ? bcadd((string) $session->closing_cash, '0', 2) : null;

        $grossSales = $this->sumOf($completed, 'total_gross');
        $returnsGross = $this->sumOf($returns, 'total_gross');

        return [
            'session_id' => (string) $session->id,
            'status' => $session->status,
            'opened_at' => $session->opened_at->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'sales_count' => $completed->count(),
            'voided_sales_count' => $sales->where('status', 'refunded')->count(),
            'voided_sales_gross' => $this->sumOf($sales->where('status', 'refunded'), 'total_gross'),
            'sales_net' => $this->sumOf($completed, 'total_net'),
            'sales_tax' => $this->sumOf($completed, 'total_tax'),
            'sales_gross' => $grossSales,
            'discounts_net' => bcadd((string) RetailSalePromotion::query()->whereIn('pos_sale_id', $saleIds)->sum('discount_net'), '0', 2),
            'returns_count' => $returns->count(),
            'returns_gross' => $returnsGross,
            'net_gross' => bcsub($grossSales, $returnsGross, 2),
            'payments_by_method' => $this->drawer->paymentsByMethod($session),
            'vat_breakdown' => $this->vatBreakdown($saleIds->all(), $returns->pluck('id')->all()),
            'cash' => $drawer + [
                'counted_cash' => $closing,
                'variance' => $closing !== null ? bcsub($closing, $drawer['expected_cash'], 2) : null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function x(PosSession $session, ?string $userId): array
    {
        $totals = $this->totals($session);

        RetailSessionReport::create([
            'tenant_id' => $session->tenant_id,
            'pos_session_id' => $session->id,
            'kind' => 'x',
            'business_date' => $this->businessDate($session),
            'totals' => $totals,
            'generated_by' => $userId,
        ]);

        return ['kind' => 'x', 'number' => null, 'business_date' => $this->businessDate($session)] + $totals;
    }

    /**
     * The Z report of a closed session, produced on the first call and returned unchanged afterwards.
     */
    public function z(PosSession $session, ?string $userId): RetailSessionReport
    {
        return DB::transaction(function () use ($session, $userId): RetailSessionReport {
            $existing = RetailSessionReport::query()->where('pos_session_id', $session->id)->where('kind', 'z')->first();

            if ($existing !== null) {
                return $existing;
            }

            $totals = $this->totals($session) + [
                'reconciliation' => $this->reconciliation($session),
            ];

            return RetailSessionReport::create([
                'tenant_id' => $session->tenant_id,
                'pos_session_id' => $session->id,
                'kind' => 'z',
                'number' => $this->numbers->next('retail_session_reports', 'Z', (string) $session->tenant_id),
                'business_date' => $this->businessDate($session),
                'totals' => $totals,
                'generated_by' => $userId,
            ]);
        });
    }

    /**
     * Takings of the session against the fiscal side: gross of the commercial documents issued for its sales net of the
     * void and return documents that correct them, and the state of the daily corrispettivi of the business day.
     *
     * @return array<string, mixed>
     */
    public function reconciliation(PosSession $session): array
    {
        $date = $this->businessDate($session);
        $saleIds = PosSale::query()->where('pos_session_id', $session->id)->where('status', 'completed')->pluck('id');
        $documents = CommercialDocument::query()->whereIn('pos_sale_id', $saleIds)->where('document_type', 'sale')->get();
        $corrections = CommercialDocument::query()->whereIn('related_document_id', $documents->pluck('id'))->whereIn('document_type', ['void', 'return'])->get();
        $returned = RetailReturn::query()->where('pos_session_id', $session->id)->sum('total_gross');
        $documented = bcsub($this->sumOf($documents, 'total_gross'), $this->sumOf($corrections, 'total_gross'), 2);
        $expected = bcsub($this->sumOf(PosSale::query()->whereIn('id', $saleIds)->get(), 'total_gross'), bcadd((string) $returned, '0', 2), 2);
        $rows = DailyCorrispettivo::query()->whereDate('business_date', $date)->get();

        return [
            'business_date' => $date,
            'documented_gross' => $documented,
            'undocumented_gross' => bcsub($expected, $documented, 2),
            'documents_count' => $documents->count(),
            'corrispettivi_day_closed' => $rows->isNotEmpty(),
            'corrispettivi_total_gross' => $this->sumOf($rows, 'total_gross'),
            'corrispettivi_gateway_status' => $rows->pluck('gateway_status')->unique()->values()->all(),
        ];
    }

    private function businessDate(PosSession $session): string
    {
        return ($session->closed_at ?? $session->opened_at ?? now())->toDateString();
    }

    /**
     * @param  iterable<mixed>  $rows
     */
    private function sumOf(iterable $rows, string $field): string
    {
        $sum = '0.00';

        foreach ($rows as $row) {
            $sum = bcadd($sum, (string) $row->{$field}, 2);
        }

        return $sum;
    }

    /**
     * Net and tax per VAT rate of the completed sales less the returned lines.
     *
     * @param  list<string>  $saleIds
     * @param  list<string>  $returnIds
     * @return list<array{tax_rate: string, net: string, tax: string, gross: string}>
     */
    private function vatBreakdown(array $saleIds, array $returnIds): array
    {
        $net = [];

        foreach (PosSaleItem::query()->whereIn('pos_sale_id', $saleIds)->get() as $item) {
            $rate = bcadd((string) $item->tax_rate, '0', 2);
            $net[$rate] = bcadd($net[$rate] ?? '0.00', (string) $item->line_total, 2);
        }

        foreach (RetailReturnLine::query()->whereIn('retail_return_id', $returnIds)->get() as $line) {
            $rate = bcadd((string) $line->tax_rate, '0', 2);
            $net[$rate] = bcsub($net[$rate] ?? '0.00', (string) $line->net_amount, 2);
        }

        ksort($net, SORT_NUMERIC);
        $rows = [];

        foreach ($net as $rate => $amount) {
            $tax = bcround(bcdiv(bcmul($amount, (string) $rate, 6), '100', 6), 2);
            $rows[] = ['tax_rate' => (string) $rate, 'net' => $amount, 'tax' => $tax, 'gross' => bcadd($amount, $tax, 2)];
        }

        return $rows;
    }
}
