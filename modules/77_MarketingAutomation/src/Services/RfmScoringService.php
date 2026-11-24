<?php

declare(strict_types=1);

namespace Modules\MarketingAutomation\Services;

use Modules\MarketingAutomation\Domain\Enums\RfmLabel;

/**
 * Pure RFM maths: 1 (worst) to 5 (best) scores and the label of the matrix cell.
 */
final class RfmScoringService
{
    /**
     * Recency is scored on fixed business bands (the fewer days, the better).
     */
    public function recencyScore(?int $days): int
    {
        return match (true) {
            $days === null => 1,
            $days <= 30 => 5,
            $days <= 90 => 4,
            $days <= 180 => 3,
            $days <= 365 => 2,
            default => 1,
        };
    }

    /**
     * Quintile score of a value inside the population (higher value, higher score).
     *
     * @param  list<float|int>  $sortedAscending
     */
    public function quantileScore(array $sortedAscending, float|int $value): int
    {
        $count = count($sortedAscending);
        if ($count === 0) {
            return 1;
        }

        $below = 0;
        foreach ($sortedAscending as $candidate) {
            if ($candidate < $value) {
                $below++;
            } else {
                break;
            }
        }

        return max(1, min(5, (int) floor($below / $count * 5) + 1));
    }

    public function label(int $r, int $f, int $m): RfmLabel
    {
        $fm = ($f + $m) / 2;

        return match (true) {
            $r >= 4 && $fm >= 4 => RfmLabel::Champions,
            $r >= 3 && $fm >= 3 => RfmLabel::Loyal,
            $r >= 4 && $f === 1 => RfmLabel::NewCustomers,
            $r >= 3 => RfmLabel::Promising,
            $r === 2 && $fm >= 3 => RfmLabel::AtRisk,
            $fm >= 4 => RfmLabel::AtRisk,
            $r === 2 => RfmLabel::Hibernating,
            default => RfmLabel::Lost,
        };
    }
}
