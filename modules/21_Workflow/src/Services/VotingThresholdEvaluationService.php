<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Illuminate\Support\Collection;
use Modules\Workflow\Models\WorkflowInstanceStep;
use Modules\Workflow\Models\WorkflowNode;

final class VotingThresholdEvaluationService
{
    /**
     * Evaluates whether the steps for a given node have reached approval consensus or rejection.
     *
     * @param  Collection<int, WorkflowInstanceStep>  $steps
     * @return array{completed: bool, outcome: string, positive_votes: int, total_votes: int, percentage: float}
     */
    public function evaluate(WorkflowNode $node, $steps): array
    {
        $totalEligible = $steps->count();
        if ($totalEligible === 0) {
            return [
                'completed' => true,
                'outcome' => 'approved',
                'positive_votes' => 0,
                'total_votes' => 0,
                'percentage' => 100.0,
            ];
        }

        $decisions = $steps->flatMap->decisions;
        $approvedCount = $decisions->where('decision', 'approved')->count();
        if ($approvedCount === 0) {
            $approvedCount = $steps->where('status', 'approved')->count();
        }

        $rejectedCount = $decisions->where('decision', 'rejected')->count();
        if ($rejectedCount === 0) {
            $rejectedCount = $steps->where('status', 'rejected')->count();
        }

        $totalVoted = $approvedCount + $rejectedCount;

        $approvalType = $node->approval_type ?? 'single';
        $thresholdPct = $node->voting_threshold_percentage !== null ? (float) $node->voting_threshold_percentage : null;
        $minVotes = $node->minimum_votes_required ?? 1;

        $percentage = $totalEligible > 0 ? ($approvedCount / $totalEligible) * 100.0 : 0.0;

        return match ($approvalType) {
            'or_parallel', 'any_approver' => [
                'completed' => $approvedCount >= 1 || $rejectedCount === $totalEligible,
                'outcome' => $approvedCount >= 1 ? 'approved' : ($rejectedCount === $totalEligible ? 'rejected' : 'pending'),
                'positive_votes' => $approvedCount,
                'total_votes' => $totalVoted,
                'percentage' => $percentage,
            ],
            'and_parallel', 'unanimous' => [
                'completed' => $approvedCount === $totalEligible || $rejectedCount > 0,
                'outcome' => $rejectedCount > 0 ? 'rejected' : ($approvedCount === $totalEligible ? 'approved' : 'pending'),
                'positive_votes' => $approvedCount,
                'total_votes' => $totalVoted,
                'percentage' => $percentage,
            ],
            'majority' => [
                'completed' => $approvedCount > ($totalEligible / 2) || $rejectedCount >= ceil($totalEligible / 2),
                'outcome' => $approvedCount > ($totalEligible / 2) ? 'approved' : ($rejectedCount >= ceil($totalEligible / 2) ? 'rejected' : 'pending'),
                'positive_votes' => $approvedCount,
                'total_votes' => $totalVoted,
                'percentage' => $percentage,
            ],
            'supermajority' => [
                'completed' => ($thresholdPct !== null && $percentage >= $thresholdPct && $approvedCount >= $minVotes) || ($totalEligible - $rejectedCount < $minVotes),
                'outcome' => ($thresholdPct !== null && $percentage >= $thresholdPct && $approvedCount >= $minVotes) ? 'approved' : (($totalEligible - $rejectedCount < $minVotes) ? 'rejected' : 'pending'),
                'positive_votes' => $approvedCount,
                'total_votes' => $totalVoted,
                'percentage' => $percentage,
            ],
            default => [ // single approver
                'completed' => $approvedCount >= 1 || $rejectedCount >= 1,
                'outcome' => $approvedCount >= 1 ? 'approved' : ($rejectedCount >= 1 ? 'rejected' : 'pending'),
                'positive_votes' => $approvedCount,
                'total_votes' => $totalVoted,
                'percentage' => $percentage,
            ],
        };
    }
}
