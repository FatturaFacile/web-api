<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Domain\Support;

/**
 * Scoring of a store audit. A criterion is `{code, weight, max_score, critical}`; the area manager gives each a score from 0 to
 * its maximum. The result is the weighted percentage of the maximum; the audit passes when the percentage reaches the threshold of
 * the template and no critical criterion is at zero. A criterion scored below 60 % of its maximum opens a corrective action.
 */
final class AuditScorer
{
    public const int CorrectiveActionBelowPercent = 60;

    /**
     * @param  list<array{code: string, weight?: int|float|string, max_score?: int|float|string, critical?: bool}>  $criteria
     * @param  array<string, int|float|string>  $scores  by criterion code
     * @return array{score: string, max_score: string, score_percent: string, passed: bool, failed_critical: list<string>, weak: list<string>}
     */
    public static function score(array $criteria, array $scores, string|int|float $thresholdPercent): array
    {
        $earned = '0';
        $max = '0';
        $failedCritical = [];
        $weak = [];

        foreach ($criteria as $criterion) {
            $weight = Dec::of($criterion['weight'] ?? 1);
            $top = Dec::of($criterion['max_score'] ?? 5);
            $given = Dec::min(Dec::max($scores[$criterion['code']] ?? 0, 0), $top);
            $ratio = Dec::div($given, $top);

            $earned = Dec::add($earned, Dec::mul($ratio, $weight));
            $max = Dec::add($max, $weight);

            if (! empty($criterion['critical']) && Dec::cmp($given, 0) === 0) {
                $failedCritical[] = $criterion['code'];
            }

            if (Dec::cmp(Dec::mul($ratio, 100), self::CorrectiveActionBelowPercent) < 0) {
                $weak[] = $criterion['code'];
            }
        }

        $percent = Dec::cmp($max, 0) === 0 ? '0' : Dec::mul(Dec::div($earned, $max), 100);

        return [
            'score' => Dec::round($earned),
            'max_score' => Dec::round($max),
            'score_percent' => Dec::round($percent),
            'passed' => $failedCritical === [] && Dec::cmp(Dec::round($percent), $thresholdPercent) >= 0,
            'failed_critical' => $failedCritical,
            'weak' => $weak,
        ];
    }
}
