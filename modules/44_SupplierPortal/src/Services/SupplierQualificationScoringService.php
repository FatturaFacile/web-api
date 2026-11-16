<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Services;

use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Facades\DB;
use Modules\Procurement\Models\Supplier;
use Modules\Procurement\Support\Decimal;
use Modules\SupplierPortal\Models\QualificationAssessment;
use Modules\SupplierPortal\Models\QualificationCriterion;
use Modules\SupplierPortal\Models\QualificationScore;

/**
 * Supplier qualification against the weighted checklist of the company. The weighted score is
 * 100 x sum(weight x score / max score) / sum(weight) over the active criteria, in exact decimals. Band A (qualified),
 * B (qualified with conditions) and C (not passed) come from the thresholds of config/supplier_portal.php.
 */
final readonly class SupplierQualificationScoringService
{
    public function __construct(private SupplierPortalLedger $ledger) {}

    /**
     * Opens a draft assessment of a supplier, optionally with the first scores.
     *
     * @param  list<array{criterion_id: string, score: numeric-string|float|int, notes?: ?string}>  $scores
     */
    public function start(string $tenantId, string $supplierId, ?string $onboardingId, ?string $notes, array $scores, ?string $userId): QualificationAssessment
    {
        return DB::transaction(function () use ($tenantId, $supplierId, $onboardingId, $notes, $scores, $userId): QualificationAssessment {
            $assessment = QualificationAssessment::create([
                'tenant_id' => $tenantId,
                'supplier_id' => $supplierId,
                'onboarding_id' => $onboardingId,
                'status' => QualificationAssessment::Draft,
                'assessed_by' => $userId,
                'notes' => $notes,
            ]);

            if ($scores !== []) {
                $this->recordScores($assessment, $scores);
            }

            return $assessment->load('scores.criterion');
        });
    }

    /**
     * Records or changes the scores of a draft assessment.
     *
     * @param  list<array{criterion_id: string, score: numeric-string|float|int, notes?: ?string}>  $scores
     *
     * @throws BusinessRuleException
     */
    public function recordScores(QualificationAssessment $assessment, array $scores): QualificationAssessment
    {
        if ($assessment->status !== QualificationAssessment::Draft) {
            throw new BusinessRuleException('A completed assessment cannot be changed.');
        }

        foreach ($scores as $index => $entry) {
            $criterion = QualificationCriterion::query()->whereKey($entry['criterion_id'])->where('is_active', true)->first();

            if ($criterion === null) {
                throw new BusinessRuleException('Score '.($index + 1).' names a criterion that does not exist or is not active.');
            }

            $score = Decimal::of($entry['score'], 2);

            if (Decimal::cmp($score, '0', 2) < 0 || Decimal::cmp($score, (string) $criterion->max_score, 2) > 0) {
                throw new BusinessRuleException("The score of {$criterion->code} must be between 0 and {$criterion->max_score}.");
            }

            QualificationScore::query()->updateOrCreate(
                ['assessment_id' => $assessment->id, 'criterion_id' => $criterion->id, 'tenant_id' => $assessment->tenant_id],
                ['score' => $score, 'notes' => $entry['notes'] ?? null],
            );
        }

        return $assessment->load('scores.criterion');
    }

    /**
     * Completes the assessment: every active criterion needs a score; the weighted score, the band and the outcome are
     * fixed.
     *
     * @throws BusinessRuleException
     */
    public function complete(QualificationAssessment $assessment, ?string $userId): QualificationAssessment
    {
        return DB::transaction(function () use ($assessment, $userId): QualificationAssessment {
            $locked = QualificationAssessment::query()->whereKey($assessment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== QualificationAssessment::Draft) {
                throw new BusinessRuleException('The assessment is already completed.');
            }

            $criteria = QualificationCriterion::query()->where('is_active', true)->get();

            if ($criteria->isEmpty()) {
                throw new BusinessRuleException('Define the qualification criteria before completing an assessment.');
            }

            $scores = QualificationScore::query()->where('assessment_id', $locked->id)->get()->keyBy('criterion_id');
            $missing = $criteria->filter(static fn (QualificationCriterion $criterion): bool => ! $scores->has($criterion->id));

            if ($missing->isNotEmpty()) {
                throw new BusinessRuleException('Score every criterion before completing: missing '.$missing->pluck('code')->implode(', ').'.');
            }

            $totalScore = $this->weightedScore($criteria->all(), $scores->map(static fn (QualificationScore $score): string => (string) $score->score)->all());
            [$band, $outcome] = $this->bandOf($totalScore);

            $locked->update([
                'status' => QualificationAssessment::Completed,
                'total_score' => $totalScore,
                'band' => $band,
                'outcome' => $outcome,
                'assessed_by' => $userId ?? $locked->assessed_by,
                'completed_at' => now(),
            ]);

            $this->ledger->record('SUPPLIER_QUALIFICATION_SCORED', $locked, ['supplier_id' => $locked->supplier_id, 'score' => $totalScore, 'band' => $band, 'outcome' => $outcome]);

            return $locked->load('scores.criterion');
        });
    }

    /**
     * Weighted score from 0 to 100 as a 2-decimal string.
     *
     * @param  list<QualificationCriterion>  $criteria
     * @param  array<string, string>  $scoresByCriterion
     */
    public function weightedScore(array $criteria, array $scoresByCriterion): string
    {
        $weighted = '0';
        $totalWeight = '0';

        foreach ($criteria as $criterion) {
            $ratio = Decimal::div(Decimal::of($scoresByCriterion[$criterion->id] ?? '0', 4), (string) $criterion->max_score, 6);
            $weighted = Decimal::add($weighted, Decimal::mul((string) $criterion->weight, $ratio, 6), 6);
            $totalWeight = Decimal::add($totalWeight, (string) $criterion->weight, 6);
        }

        if (! Decimal::isPositive($totalWeight)) {
            return '0.00';
        }

        return Decimal::round(Decimal::mul(Decimal::div($weighted, $totalWeight, 8), '100', 8), 2);
    }

    /**
     * @return array{0: string, 1: string} band and outcome
     */
    public function bandOf(string $totalScore): array
    {
        if (Decimal::cmp($totalScore, (string) config('supplier_portal.qualification.qualified_min_score'), 2) >= 0) {
            return ['A', QualificationAssessment::Qualified];
        }

        if (Decimal::cmp($totalScore, (string) config('supplier_portal.qualification.conditional_min_score'), 2) >= 0) {
            return ['B', QualificationAssessment::Conditional];
        }

        return ['C', QualificationAssessment::NotQualified];
    }

    /**
     * The latest completed assessment of the supplier.
     */
    public function latestCompleted(string $supplierId): ?QualificationAssessment
    {
        return QualificationAssessment::query()
            ->where('supplier_id', $supplierId)
            ->where('status', QualificationAssessment::Completed)
            ->orderByDesc('completed_at')
            ->first();
    }

    public function supplierExists(string $supplierId): bool
    {
        return Supplier::query()->whereKey($supplierId)->exists();
    }
}
