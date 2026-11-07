<?php

declare(strict_types=1);

namespace Modules\FiscalRetail\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FiscalRetail\Models\CommercialDocumentLine;

/**
 * Totals of the commercial documents by day and VAT rate. Sales add up; voids and returns are subtracted.
 */
final class CorrispettiviAggregator
{
    /**
     * @return list<array{business_date: string, tax_rate: string, vat_nature: string, sales_net: string, sales_tax: string, sales_gross: string, voided_gross: string, returned_gross: string, total_net: string, total_tax: string, total_gross: string, document_count: int}>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $lines = CommercialDocumentLine::query()
            ->join('commercial_documents as document', 'document.id', '=', 'commercial_document_lines.commercial_document_id')
            ->whereBetween('document.issue_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('document.issue_date')
            ->orderBy('commercial_document_lines.tax_rate')
            ->get([
                'commercial_document_lines.*',
                'document.issue_date as business_date',
                'document.document_type',
            ]);

        return $lines
            ->groupBy(fn (CommercialDocumentLine $line): string => implode('|', [
                substr((string) $line->getAttribute('business_date'), 0, 10), (string) $line->tax_rate, (string) $line->vat_nature,
            ]))
            ->map(fn (Collection $group): array => $this->row($group))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, CommercialDocumentLine>  $lines
     * @return array<string, mixed>
     */
    private function row(Collection $lines): array
    {
        $first = $lines->first();
        $sum = static fn (Collection $items, string $field): string => $items->reduce(
            static fn (string $total, CommercialDocumentLine $line): string => bcadd($total, (string) $line->getAttribute($field), 2),
            '0.00',
        );

        $sales = $lines->where('document_type', 'sale');
        $voids = $lines->where('document_type', 'void');
        $returns = $lines->where('document_type', 'return');
        $reversals = $voids->concat($returns);

        return [
            'business_date' => substr((string) $first->getAttribute('business_date'), 0, 10),
            'tax_rate' => (string) $first->tax_rate,
            'vat_nature' => (string) $first->vat_nature,
            'sales_net' => $sum($sales, 'net_amount'),
            'sales_tax' => $sum($sales, 'tax_amount'),
            'sales_gross' => $sum($sales, 'gross_amount'),
            'voided_gross' => $sum($voids, 'gross_amount'),
            'returned_gross' => $sum($returns, 'gross_amount'),
            'total_net' => bcsub($sum($sales, 'net_amount'), $sum($reversals, 'net_amount'), 2),
            'total_tax' => bcsub($sum($sales, 'tax_amount'), $sum($reversals, 'tax_amount'), 2),
            'total_gross' => bcsub($sum($sales, 'gross_amount'), $sum($reversals, 'gross_amount'), 2),
            'document_count' => $lines->pluck('commercial_document_id')->unique()->count(),
        ];
    }
}
