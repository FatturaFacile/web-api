<?php

declare(strict_types=1);

namespace Modules\Sustainability\Services;

use Modules\Sustainability\Models\EsgActivityRecord;
use Modules\Sustainability\Models\EsgReportingPeriod;
use Modules\Sustainability\Models\EsgSupplierDataRequest;
use Modules\Sustainability\Support\ActivityCatalog;
use Modules\Sustainability\Support\Decimal;

/**
 * Scope 3 of a period by the 15 GHG Protocol categories, with the share of supplier-specific data (records that come
 * from a supplier data request), the share of estimated data and the open supplier requests per category. Sums are exact.
 */
final class Scope3Service
{
    /**
     * @return array<string, mixed>
     */
    public function report(EsgReportingPeriod $period): array
    {
        $buckets = $this->buckets($period);
        $total = '0';
        foreach ($buckets as $bucket) {
            $total = Decimal::add($total, $bucket['total']);
        }

        $openRequests = EsgSupplierDataRequest::query()
            ->where('reporting_period_id', $period->id)
            ->whereIn('status', [EsgSupplierDataRequest::Requested, EsgSupplierDataRequest::Received])
            ->get()
            ->countBy('scope3_category');

        $categories = [];
        foreach (ActivityCatalog::Scope3Categories as $number => $key) {
            $bucket = $buckets[$number] ?? ['total' => '0', 'records' => 0, 'supplier' => '0', 'estimated' => '0'];
            $categories[] = [
                'category' => $number,
                'key' => $key,
                'co2e_kg' => Decimal::round($bucket['total'], 4),
                'share_percent' => $this->percentOf($bucket['total'], $total),
                'records' => $bucket['records'],
                'supplier_specific_kg' => Decimal::round($bucket['supplier'], 4),
                'supplier_specific_percent' => $this->percentOf($bucket['supplier'], $bucket['total']),
                'estimated_percent' => $this->percentOf($bucket['estimated'], $bucket['total']),
                'open_supplier_requests' => (int) ($openRequests[$number] ?? 0),
                'has_data' => $bucket['records'] > 0,
            ];
        }

        $unmapped = $buckets[0] ?? ['total' => '0', 'records' => 0];
        $supplierTotal = '0';
        foreach ($buckets as $bucket) {
            $supplierTotal = Decimal::add($supplierTotal, $bucket['supplier']);
        }

        return [
            'period_id' => $period->id,
            'total_kg' => Decimal::round($total, 4),
            'total_tco2e' => Decimal::round(Decimal::div($total, '1000'), 3),
            'supplier_specific_percent' => $this->percentOf($supplierTotal, $total),
            'categories_with_data' => count(array_filter($categories, static fn (array $row): bool => $row['has_data'])),
            'categories' => $categories,
            'unmapped_kg' => Decimal::round($unmapped['total'], 4),
        ];
    }

    /**
     * Numbers of the Scope 3 categories without any activity record in the period.
     *
     * @return list<int>
     */
    public function categoriesWithoutData(EsgReportingPeriod $period): array
    {
        $buckets = $this->buckets($period);

        return array_values(array_filter(array_keys(ActivityCatalog::Scope3Categories), static fn (int $number): bool => ! isset($buckets[$number])));
    }

    /**
     * @return array<int, array{total: string, records: int, supplier: string, estimated: string}>
     */
    private function buckets(EsgReportingPeriod $period): array
    {
        $buckets = [];

        $rows = EsgActivityRecord::query()
            ->where('tenant_id', $period->tenant_id)
            ->where('scope', 3)
            ->whereDate('activity_date', '>=', $period->starts_on->toDateString())
            ->whereDate('activity_date', '<=', $period->ends_on->toDateString())
            ->select(['category', 'data_quality', 'source_type', 'co2e_kg'])
            ->cursor();

        foreach ($rows as $row) {
            $number = ActivityCatalog::scope3NumberOf((string) $row->category) ?? 0;
            $bucket = $buckets[$number] ?? ['total' => '0', 'records' => 0, 'supplier' => '0', 'estimated' => '0'];
            $kg = (string) $row->co2e_kg;

            $bucket['total'] = Decimal::add($bucket['total'], $kg);
            $bucket['records']++;
            if ($row->source_type === 'esg_supplier_data_request') {
                $bucket['supplier'] = Decimal::add($bucket['supplier'], $kg);
            }
            if ($row->data_quality === 'estimated') {
                $bucket['estimated'] = Decimal::add($bucket['estimated'], $kg);
            }

            $buckets[$number] = $bucket;
        }

        return $buckets;
    }

    private function percentOf(string $part, string $whole): string
    {
        return Decimal::isPositive($whole) ? Decimal::round(Decimal::div(Decimal::mul($part, '100'), $whole), 2) : '0.00';
    }
}
