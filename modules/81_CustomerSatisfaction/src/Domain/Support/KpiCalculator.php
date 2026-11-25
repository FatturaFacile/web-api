<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Support;

use Modules\CustomerSatisfaction\Domain\Enums\SurveyType;

/**
 * Pure KPI maths of the satisfaction answers (ISO 9001:2015 § 9.1.2):
 *  - NPS = % promoters (9-10) - % detractors (0-6), passives 7-8;
 *  - CSAT = % of positive ratings (4-5 out of 5) and the average score;
 *  - CES = average effort (1-7, lower is better) and the low-effort rate (1-3).
 */
final class KpiCalculator
{
    /**
     * @param  iterable<array{survey_type: string, score: int|string}>  $rows
     * @return array{total: int, nps: array<string, int|float|null>, csat: array<string, int|float|null>, ces: array<string, int|float|null>}
     */
    public static function compute(iterable $rows): array
    {
        $nps = ['responses' => 0, 'promoters' => 0, 'passives' => 0, 'detractors' => 0];
        $csat = ['responses' => 0, 'positive' => 0, 'sum' => 0];
        $ces = ['responses' => 0, 'low_effort' => 0, 'sum' => 0];
        $total = 0;

        foreach ($rows as $row) {
            $type = SurveyType::tryFrom((string) $row['survey_type']);
            if ($type === null) {
                continue;
            }

            $score = (int) $row['score'];
            $total++;

            switch ($type) {
                case SurveyType::Nps:
                    $nps['responses']++;
                    $band = $type->band($score)->value;
                    $nps[$band === 'promoter' ? 'promoters' : ($band === 'passive' ? 'passives' : 'detractors')]++;
                    break;
                case SurveyType::Csat:
                    $csat['responses']++;
                    $csat['sum'] += $score;
                    $csat['positive'] += $type->isPositive($score) ? 1 : 0;
                    break;
                case SurveyType::Ces:
                    $ces['responses']++;
                    $ces['sum'] += $score;
                    $ces['low_effort'] += $type->isPositive($score) ? 1 : 0;
                    break;
            }
        }

        $promotersPct = self::pct($nps['promoters'], $nps['responses']);
        $detractorsPct = self::pct($nps['detractors'], $nps['responses']);

        return [
            'total' => $total,
            'nps' => [
                'responses' => $nps['responses'],
                'promoters' => $nps['promoters'],
                'passives' => $nps['passives'],
                'detractors' => $nps['detractors'],
                'promoters_pct' => $promotersPct,
                'passives_pct' => self::pct($nps['passives'], $nps['responses']),
                'detractors_pct' => $detractorsPct,
                'score' => $nps['responses'] === 0 ? null : round($promotersPct - $detractorsPct, 1),
            ],
            'csat' => [
                'responses' => $csat['responses'],
                'positive' => $csat['positive'],
                'positive_pct' => $csat['responses'] === 0 ? null : self::pct($csat['positive'], $csat['responses']),
                'average' => $csat['responses'] === 0 ? null : round($csat['sum'] / $csat['responses'], 2),
            ],
            'ces' => [
                'responses' => $ces['responses'],
                'low_effort' => $ces['low_effort'],
                'low_effort_rate' => $ces['responses'] === 0 ? null : self::pct($ces['low_effort'], $ces['responses']),
                'average' => $ces['responses'] === 0 ? null : round($ces['sum'] / $ces['responses'], 2),
            ],
        ];
    }

    public static function pct(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part * 100 / $whole, 1);
    }
}
