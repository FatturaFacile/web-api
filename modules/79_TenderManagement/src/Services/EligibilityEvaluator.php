<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Contracts\CompanyCapabilityProviderInterface;
use Modules\TenderManagement\Domain\Enums\ParticipationMode;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Domain\ValueObjects\CriterionResult;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

/**
 * Go/No-Go evaluation: compares the requirements of the tender (specific turnover, similar projects, SOA categories, Swiss
 * references, certifications) with the ERP track record and the qualification vault, scores the match and tells whether the
 * company can bid alone or must join a temporary grouping (RTI / consortium) or borrow the missing requirements
 * (avvalimento), with the quota or the SOA gap to cover.
 *
 * A grouping needs the lead company to hold the majority of the quantitative requirements: below that share the evaluator
 * recommends the avvalimento instead (Art. 68 and 104 D.Lgs. 36/2023).
 */
final class EligibilityEvaluator
{
    /** Minimum share of the quantitative requirements the mandataria must cover itself. */
    private const string MandatariaShare = '0.40';

    public function __construct(
        private readonly TenderStrategyRegistry $strategies,
        private readonly CompanyCapabilityProviderInterface $capabilities,
    ) {}

    /**
     * @param  Collection<int, QualificationVaultDocument>  $vault
     * @return array<string, mixed>
     */
    public function evaluate(TenderNotice $notice, Collection $vault, CarbonImmutable $today): array
    {
        $strategy = $this->strategies->for($notice->jurisdiction);
        $profile = $this->capabilities->profileFor($notice, $today);
        $criteria = $strategy->evaluateRequirements($notice, $profile, $vault, $today);

        $total = array_sum(array_map(static fn (CriterionResult $row): int => $row->weight, $criteria));
        $earned = array_sum(array_map(static fn (CriterionResult $row): int => $row->met ? $row->weight : 0, $criteria));
        $score = $total === 0 ? 100 : (int) round($earned * 100 / $total);

        $unmet = array_values(array_filter($criteria, static fn (CriterionResult $row): bool => ! $row->met));
        $coverage = $this->quantitativeCoverage($criteria);
        $mode = $this->recommendedMode($unmet, $coverage);
        $gaps = $this->gaps($notice, $criteria, $coverage, $mode);

        $decision = $unmet === [] ? 'go' : ($score >= 50 ? 'conditional' : 'no_go');
        $issues = $this->issues($mode, $gaps, $coverage);

        return [
            'strategy' => $strategy->regime(),
            'can_bid_solo' => $unmet === [],
            'recommended_mode' => $mode->value,
            'overall_score' => $score,
            'decision' => $decision,
            'criteria' => array_map(static fn (CriterionResult $row): array => $row->toArray(), $criteria),
            'gaps' => $gaps,
            'issues' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $issues),
            'evaluated_at' => $today->toIso8601String(),
        ];
    }

    /**
     * Lowest ratio between what the company has and what is required over the quantitative criteria (turnover, similar projects).
     *
     * @param  list<CriterionResult>  $criteria
     */
    private function quantitativeCoverage(array $criteria): string
    {
        $ratios = [];

        foreach ($criteria as $row) {
            if (in_array($row->key, ['turnover', 'similar_projects'], true) && Dec::isPositive($row->required)) {
                $ratios[] = Dec::min(1, Dec::div($row->available, $row->required));
            }
        }

        return $ratios === [] ? '1' : array_reduce($ratios, static fn (string $carry, string $ratio): string => Dec::min($carry, $ratio), '1');
    }

    /**
     * @param  list<CriterionResult>  $unmet
     */
    private function recommendedMode(array $unmet, string $coverage): ParticipationMode
    {
        if ($unmet === []) {
            return ParticipationMode::Single;
        }

        // Only qualitative gaps (an SOA class, a certification) are borrowed from an auxiliary company; a quantitative gap
        // the company can half cover is filled by a partner in a grouping.
        if (Dec::cmp($coverage, 1) >= 0) {
            return ParticipationMode::Avvalimento;
        }

        return Dec::cmp($coverage, self::MandatariaShare) >= 0 ? ParticipationMode::Rti : ParticipationMode::Avvalimento;
    }

    /**
     * @param  list<CriterionResult>  $criteria
     * @return array<string, mixed>
     */
    private function gaps(TenderNotice $notice, array $criteria, string $coverage, ParticipationMode $mode): array
    {
        $turnoverGap = '0.00';
        $projectsGap = 0;
        $soa = [];
        $missing = [];

        foreach ($criteria as $row) {
            if ($row->key === 'turnover') {
                $turnoverGap = (string) $row->gap;
            } elseif ($row->key === 'similar_projects') {
                $projectsGap = (int) $row->gap;
            } elseif (str_starts_with($row->key, 'soa:')) {
                $soa[] = ['category' => substr($row->key, 4), 'required_classification' => (string) $row->required, 'available_classification' => $row->available === null ? null : (string) $row->available, 'met' => $row->met];
            } elseif (! $row->met && (str_starts_with($row->key, 'certification:') || str_starts_with($row->key, 'document:'))) {
                $missing[] = substr($row->key, str_starts_with($row->key, 'certification:') ? 14 : 9);
            }
        }

        return [
            'turnover_gap' => $turnoverGap,
            'similar_projects_gap' => $projectsGap,
            'soa' => $soa,
            'missing_certifications' => $missing,
            'rti_quota_percentage_suggested' => $mode === ParticipationMode::Rti ? Dec::money(Dec::mul($coverage, 100)) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $gaps
     * @return list<ComplianceIssue>
     */
    private function issues(ParticipationMode $mode, array $gaps, string $coverage): array
    {
        $issues = [];

        if ($mode === ParticipationMode::Single) {
            return [ComplianceIssue::info('solo_eligible')];
        }

        if (Dec::isPositive($gaps['turnover_gap'])) {
            $issues[] = ComplianceIssue::warning('turnover_gap', ['gap' => $gaps['turnover_gap']]);
        }

        $issues[] = $mode === ParticipationMode::Rti
            ? ComplianceIssue::info('rti_recommended', ['quota' => $gaps['rti_quota_percentage_suggested']])
            : ComplianceIssue::info('avvalimento_recommended');

        return $issues;
    }
}
