<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\TenderManagement\Domain\Enums\AwardCriterion;
use Modules\TenderManagement\Domain\Enums\FormulaType;
use Modules\TenderManagement\Domain\Support\Dec;
use Modules\TenderManagement\Domain\ValueObjects\ComplianceIssue;
use Modules\TenderManagement\Domain\ValueObjects\OfferInput;
use Modules\TenderManagement\Exceptions\TenderException;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Models\TenderNotice;
use Modules\TenderManagement\Strategies\TenderStrategyRegistry;

/**
 * Simulates an economic offer: the offered amount and net margin, the isolated company labour and safety costs (Art. 108
 * co. 9), the OEPV score with the linear, bilinear or interpolation formula, the anomaly threshold and the provisional bid
 * bond with its cumulative reductions. Pure arithmetic on decimal strings, no persistence.
 */
final class OfferSimulator
{
    public function __construct(
        private readonly TenderStrategyRegistry $strategies,
        private readonly BidBondCalculator $bidBonds,
    ) {}

    /**
     * @param  Collection<int, QualificationVaultDocument>  $vault
     * @return array<string, mixed>
     */
    public function simulate(TenderNotice $notice, OfferInput $input, Collection $vault, CarbonImmutable $today): array
    {
        $discount = Dec::of($input->discountPercentage);

        if (Dec::cmp($discount, 0) < 0 || Dec::cmp($discount, 100) > 0) {
            throw new TenderException('discount_out_of_range');
        }

        $strategy = $this->strategies->for($notice->jurisdiction);
        $nonSubject = Dec::of($notice->non_subject_safety_costs_amount);
        $discountable = Dec::max(Dec::sub($notice->base_auction_amount, $nonSubject), 0);
        $offered = Dec::money(Dec::add(Dec::mul($discountable, Dec::sub(1, Dec::div($discount, 100))), $nonSubject));

        $cost = $input->estimatedTotalCost !== null && $input->estimatedTotalCost !== ''
            ? Dec::of($input->estimatedTotalCost)
            : Dec::add(Dec::add($input->otherDirectCosts, $input->companyLaborCosts), $input->companySafetyCosts);
        $margin = Dec::money(Dec::sub($offered, $cost));

        $issues = $strategy->validateEconomicOffer($notice, $input);

        if (Dec::cmp($offered, $cost) < 0) {
            $issues[] = ComplianceIssue::warning('offer_below_cost', [], 'economic');
        } elseif (Dec::cmp($margin, 0) < 0) {
            $issues[] = ComplianceIssue::warning('margin_negative', [], 'economic');
        }

        $scoring = $this->score($notice, $input, $discount, $discountable, $nonSubject, $offered);

        if ($scoring['anomaly']['is_anomalous']) {
            $issues[] = ComplianceIssue::warning('anomaly_threshold_exceeded', [], 'economic');
        }

        $bond = $this->bidBonds->calculate($notice, $strategy, $vault, $input->bidBasePercentage, $today);

        return [
            'discount_percentage' => Dec::percent($discount),
            'base_auction_amount' => Dec::money($notice->base_auction_amount),
            'discountable_base' => Dec::money($discountable),
            'non_subject_safety_costs_amount' => Dec::money($nonSubject),
            'offered_amount' => $offered,
            'estimated_total_cost' => Dec::money($cost),
            'net_margin_amount' => $margin,
            'net_margin_percentage' => Dec::percent(Dec::mul(Dec::div($margin, $offered), 100)),
            'company_safety_costs_declared' => $input->companySafetyCosts === null ? null : Dec::money($input->companySafetyCosts),
            'company_labor_costs_declared' => $input->companyLaborCosts === null ? null : Dec::money($input->companyLaborCosts),
            'scoring' => $scoring,
            'bid_bond' => $bond,
            'anac_contribution_amount' => $strategy->anacContribution($notice),
            'issues' => array_map(static fn (ComplianceIssue $issue): array => $issue->toArray(), $issues),
            'submission_blocked' => array_filter($issues, static fn (ComplianceIssue $issue): bool => $issue->isBlocking()) !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function score(TenderNotice $notice, OfferInput $input, string $discount, string $discountable, string $nonSubject, string $offered): array
    {
        $competitors = array_map(Dec::of(...), $input->competitorDiscounts);
        $best = Dec::max($discount, Dec::max($input->bestCompetitorDiscount, $competitors === [] ? 0 : array_reduce($competitors, static fn (string $carry, string $value): string => Dec::max($carry, $value), '0')));

        if ($notice->award_criterion === AwardCriterion::LowestPrice) {
            return [
                'criterion' => AwardCriterion::LowestPrice->value,
                'formula' => null,
                'technical_score' => null,
                'technical_max' => null,
                'economic_score' => null,
                'economic_max' => null,
                'total_score' => null,
                'best_discount_assumed' => Dec::percent($best),
                'anomaly' => $this->lowestPriceAnomaly($discount, $competitors),
            ];
        }

        $formula = $notice->formula_type ?? throw new TenderException('formula_required');
        $technicalMax = Dec::of($notice->technical_max_score ?? config('tender_management.scoring.technical_max_default'));
        $economicMax = Dec::of($notice->economic_max_score ?? config('tender_management.scoring.economic_max_default'));
        $technical = Dec::min($input->technicalScoreEstimate ?? 0, $technicalMax);

        $economic = match ($formula) {
            FormulaType::Linear => Dec::mul($economicMax, Dec::div($discount, $best)),
            FormulaType::Bilinear => $this->bilinear($economicMax, $discount, $best, $competitors),
            FormulaType::Interpolation => $this->interpolation($economicMax, $discountable, $nonSubject, $offered, $competitors),
        };
        $economic = Dec::min($economic, $economicMax);

        $share = (string) config('tender_management.scoring.anomaly_share', '0.80');
        $anomalous = Dec::isPositive($technicalMax) && Dec::isPositive($economicMax)
            && Dec::cmp($technical, Dec::mul($technicalMax, $share)) >= 0
            && Dec::cmp($economic, Dec::mul($economicMax, $share)) >= 0;

        return [
            'criterion' => AwardCriterion::Oepv->value,
            'formula' => $formula->value,
            'technical_score' => Dec::money($technical),
            'technical_max' => Dec::money($technicalMax),
            'economic_score' => Dec::money($economic),
            'economic_max' => Dec::money($economicMax),
            'total_score' => Dec::money(Dec::add($technical, $economic)),
            'best_discount_assumed' => Dec::percent($best),
            'anomaly' => ['is_anomalous' => $anomalous, 'threshold_type' => 'oepv_four_fifths', 'threshold_value' => Dec::percent(Dec::mul($share, 100)), 'message_code' => $anomalous ? 'anomaly_threshold_exceeded' : null],
        ];
    }

    /**
     * @param  list<string>  $competitors
     */
    private function bilinear(string $economicMax, string $discount, string $best, array $competitors): string
    {
        $factor = (string) config('tender_management.scoring.bilinear_factor', '0.85');
        $threshold = Dec::mean($competitors) ?? Dec::div($best, 2);

        if (! Dec::isPositive($threshold)) {
            return Dec::isPositive($best) ? Dec::mul($economicMax, Dec::div($discount, $best)) : '0';
        }

        if (Dec::cmp($discount, $threshold) <= 0) {
            return Dec::mul(Dec::mul($economicMax, $factor), Dec::div($discount, $threshold));
        }

        $span = Dec::sub($best, $threshold);

        if (! Dec::isPositive($span)) {
            return $economicMax;
        }

        $above = Dec::mul(Dec::sub(1, $factor), Dec::div(Dec::sub($discount, $threshold), $span));

        return Dec::mul($economicMax, Dec::add($factor, $above));
    }

    /**
     * Price based inverse proportion: the lowest offered amount scores the maximum.
     *
     * @param  list<string>  $competitors
     */
    private function interpolation(string $economicMax, string $discountable, string $nonSubject, string $offered, array $competitors): string
    {
        $lowest = Dec::of($offered);

        foreach ($competitors as $competitor) {
            $price = Dec::add(Dec::mul($discountable, Dec::sub(1, Dec::div($competitor, 100))), $nonSubject);
            $lowest = Dec::min($lowest, $price);
        }

        return Dec::isPositive($offered) ? Dec::mul($economicMax, Dec::div($lowest, $offered)) : '0';
    }

    /**
     * Automatic threshold of the lowest price criterion: the average discount plus the average excess of the discounts above it.
     *
     * @param  list<string>  $competitors
     * @return array{is_anomalous: bool, threshold_type: string|null, threshold_value: string|null, message_code: string|null}
     */
    private function lowestPriceAnomaly(string $discount, array $competitors): array
    {
        $mean = Dec::mean($competitors);

        if ($mean === null) {
            return ['is_anomalous' => false, 'threshold_type' => null, 'threshold_value' => null, 'message_code' => null];
        }

        $excesses = [];
        foreach ($competitors as $value) {
            if (Dec::cmp($value, $mean) > 0) {
                $excesses[] = Dec::sub($value, $mean);
            }
        }

        $threshold = Dec::add($mean, Dec::mean($excesses) ?? 0);
        $anomalous = Dec::cmp($discount, $threshold) > 0;

        return ['is_anomalous' => $anomalous, 'threshold_type' => 'average_discount', 'threshold_value' => Dec::percent($threshold), 'message_code' => $anomalous ? 'anomaly_threshold_exceeded' : null];
    }
}
