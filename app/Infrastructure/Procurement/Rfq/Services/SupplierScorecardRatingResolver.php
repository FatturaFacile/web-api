<?php

declare(strict_types=1);

namespace App\Infrastructure\Procurement\Rfq\Services;

use App\Domain\Procurement\Rfq\Contracts\SupplierRatingResolverInterface;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Models\SupplierEvaluation;
use Modules\Procurement\Models\SupplierScorecard;

/**
 * Resolves a normalized 0..100 rating from Mod 043 Supplier Scorecard / Evaluation / Rating grade.
 */
final class SupplierScorecardRatingResolver implements SupplierRatingResolverInterface
{
    public function resolveRating(string $supplierId): float
    {
        return $this->resolveRatings([$supplierId])[$supplierId] ?? 75.0;
    }

    /**
     * @param  list<string>  $supplierIds
     * @return array<string, float>
     */
    public function resolveRatings(array $supplierIds): array
    {
        if ($supplierIds === []) {
            return [];
        }

        $scorecards = SupplierScorecard::query()
            ->whereIn('supplier_id', $supplierIds)
            ->orderByDesc('year')
            ->orderByDesc('quarter')
            ->get()
            ->groupBy('supplier_id')
            ->map(static fn ($group) => $group->first());

        $evaluations = SupplierEvaluation::query()
            ->whereIn('supplier_id', $supplierIds)
            ->orderByDesc('evaluation_period_end')
            ->get()
            ->groupBy('supplier_id')
            ->map(static fn ($group) => $group->first());

        $suppliers = Supplier::query()
            ->whereIn('id', $supplierIds)
            ->get(['id', 'rating'])
            ->keyBy('id');

        $ratings = [];

        foreach ($supplierIds as $supplierId) {
            /** @var SupplierScorecard|null $scorecard */
            $scorecard = $scorecards->get($supplierId);
            if ($scorecard !== null) {
                $otif = (float) $scorecard->otif_percentage;
                $compliance = (float) $scorecard->compliance_score;
                $ratings[$supplierId] = round(max(0.0, min(100.0, ($otif + $compliance) / 2.0)), 2);

                continue;
            }

            /** @var SupplierEvaluation|null $evaluation */
            $evaluation = $evaluations->get($supplierId);
            if ($evaluation !== null) {
                $otd = (float) $evaluation->on_time_delivery_rate;
                $quality = (float) $evaluation->quality_acceptance_rate;
                $price = (float) $evaluation->price_competitiveness_score;
                $ratings[$supplierId] = round(max(0.0, min(100.0, ($otd + $quality + $price) / 3.0)), 2);

                continue;
            }

            $grade = strtoupper((string) ($suppliers->get($supplierId)->rating ?? 'B'));
            $ratings[$supplierId] = match ($grade) {
                'A+', 'A' => 92.0,
                'B' => 78.0,
                'C' => 60.0,
                'D' => 40.0,
                default => is_numeric($grade) ? round(max(0.0, min(100.0, (float) $grade)), 2) : 75.0,
            };
        }

        return $ratings;
    }
}
