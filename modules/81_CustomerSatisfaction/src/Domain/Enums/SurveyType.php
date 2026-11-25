<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Domain\Enums;

use Modules\CustomerSatisfaction\Domain\Enums\Concerns\EnumValues;

/**
 * The three satisfaction instruments: NPS (0-10), CSAT (1-5) and CES (1-7), with their bands and detractor rules.
 */
enum SurveyType: string
{
    use EnumValues;

    case Nps = 'nps';
    case Csat = 'csat';
    case Ces = 'ces';

    public function min(): int
    {
        return $this === self::Nps ? 0 : 1;
    }

    public function max(): int
    {
        return match ($this) {
            self::Nps => 10,
            self::Csat => 5,
            self::Ces => 7,
        };
    }

    /**
     * NPS: 0-6 detractors; CSAT: 1-2 dissatisfied; CES: 5-7 high effort (a high score is the bad answer).
     */
    public function defaultDetractorThreshold(): int
    {
        return match ($this) {
            self::Nps => 6,
            self::Csat => 2,
            self::Ces => 5,
        };
    }

    public function isDetractor(int $score, ?int $threshold = null): bool
    {
        $limit = $threshold ?? $this->defaultDetractorThreshold();

        return $this === self::Ces ? $score >= $limit : $score <= $limit;
    }

    public function isValidScore(int $score): bool
    {
        return $score >= $this->min() && $score <= $this->max();
    }

    /**
     * Promoters 9-10, passives 7-8, detractors 0-6 (NPS); satisfied 4-5, neutral 3, dissatisfied 1-2 (CSAT);
     * satisfied (low effort) 1-3, neutral 4, dissatisfied (high effort) 5-7 (CES).
     */
    public function band(int $score): ResponseBand
    {
        return match ($this) {
            self::Nps => match (true) {
                $score >= 9 => ResponseBand::Promoter,
                $score >= 7 => ResponseBand::Passive,
                default => ResponseBand::Detractor,
            },
            self::Csat => match (true) {
                $score >= 4 => ResponseBand::Satisfied,
                $score === 3 => ResponseBand::Neutral,
                default => ResponseBand::Dissatisfied,
            },
            self::Ces => match (true) {
                $score <= 3 => ResponseBand::Satisfied,
                $score === 4 => ResponseBand::Neutral,
                default => ResponseBand::Dissatisfied,
            },
        };
    }

    /**
     * A positive rating counts for the CSAT percentage (4-5) and the CES low-effort rate (1-3).
     */
    public function isPositive(int $score): bool
    {
        return match ($this) {
            self::Nps => $score >= 9,
            self::Csat => $score >= 4,
            self::Ces => $score <= 3,
        };
    }
}
