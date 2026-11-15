<?php

declare(strict_types=1);

namespace Modules\CreditManagement\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CreditManagement\Contracts\ReceivablesLookupInterface;
use Modules\CreditManagement\Models\CreditRatingBand;
use Modules\CreditManagement\Models\CreditScore;
use Modules\CreditManagement\Models\CreditScorecardFactor;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Credit scoring of the customers. The score is the weighted average of the 0-100 sub-scores of the active scorecard
 * factors; the rating band that contains the score gives the rating, the risk level and the rule of the automatic
 * credit limit proposal (a share of the quarterly turnover of the customer, capped by the ceiling of the band).
 *
 * Sub-scores of the automatic sources:
 * - overdue_ratio: 100 with nothing overdue, 0 with everything overdue;
 * - max_days_overdue: 100 with nothing overdue, falling linearly to 0 at 90 days;
 * - limit_utilisation: 100 up to 70% of the limit, falling linearly to 0 at 150%; 50 when the customer has no limit;
 * - relationship_months: 100 from 36 months of relationship, linear below;
 * - manual: the value (0-100) entered by the analyst for the factor code.
 */
final readonly class CreditScoringService
{
    /**
     * The default scorecard: code, name, source and weight of every factor (the weights add up to 100).
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public const array DefaultFactors = [
        ['overdue_ratio', 'Share of the exposure overdue', CreditScorecardFactor::OverdueRatio, '30'],
        ['max_days_overdue', 'Days overdue of the oldest invoice', CreditScorecardFactor::MaxDaysOverdue, '30'],
        ['limit_utilisation', 'Use of the credit limit', CreditScorecardFactor::LimitUtilisation, '20'],
        ['relationship_months', 'Months of relationship', CreditScorecardFactor::RelationshipMonths, '10'],
        ['analyst_opinion', 'Analyst opinion', CreditScorecardFactor::Manual, '10'],
    ];

    /**
     * The default rating bands: code, name, minimum and maximum score, risk level, limit multiplier and ceiling.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string}>
     */
    public const array DefaultBands = [
        ['A', 'Excellent', '85.00', '100.00', 'low', '1.50', '250000.00'],
        ['B', 'Good', '70.00', '84.99', 'low', '1.00', '100000.00'],
        ['C', 'Fair', '50.00', '69.99', 'medium', '0.60', '30000.00'],
        ['D', 'Weak', '30.00', '49.99', 'high', '0.25', '5000.00'],
        ['E', 'Critical', '0.00', '29.99', 'critical', '0.00', '0.00'],
    ];

    public function __construct(private ReceivablesLookupInterface $receivables, private ExposureService $exposures) {}

    /**
     * Creates the default scorecard and rating bands when the company has none.
     *
     * @return array{factors: int, bands: int}
     */
    public function seedDefaults(string $tenantId): array
    {
        if (CreditScorecardFactor::query()->where('tenant_id', $tenantId)->exists() || CreditRatingBand::query()->where('tenant_id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['scorecard' => [__('credit_errors.scorecard.already_configured')]]);
        }

        return DB::transaction(function () use ($tenantId): array {
            $factors = self::DefaultFactors;

            foreach ($factors as $index => [$code, $name, $source, $weight]) {
                CreditScorecardFactor::create(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'source' => $source, 'weight' => $weight, 'is_active' => true, 'sort_order' => $index]);
            }

            $bands = self::DefaultBands;

            foreach ($bands as [$code, $name, $min, $max, $risk, $multiplier, $ceiling]) {
                CreditRatingBand::create(['tenant_id' => $tenantId, 'code' => $code, 'name' => $name, 'min_score' => $min, 'max_score' => $max, 'risk_level' => $risk, 'limit_multiplier' => $multiplier, 'limit_ceiling' => $ceiling]);
            }

            return ['factors' => count($factors), 'bands' => count($bands)];
        });
    }

    /**
     * Scores the customer today and stores the result in the score history.
     *
     * @param  array<string, int|float|string>  $manualInputs  0-100 value per factor code of a manual factor
     */
    public function score(string $tenantId, string $customerId, array $manualInputs, ?string $userId, ?string $notes): CreditScore
    {
        $this->assertCustomer($tenantId, $customerId);

        $factors = CreditScorecardFactor::query()->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('sort_order')->orderBy('code')->get();

        if ($factors->isEmpty()) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.scorecard.missing_factors')]]);
        }

        $today = Carbon::today();
        $exposure = $this->exposures->forCustomer($tenantId, $customerId, $today);
        $months = $this->relationshipMonths($tenantId, $customerId, $today);
        $breakdown = [];
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($factors as $factor) {
            [$raw, $sub] = $this->subScore($factor, $exposure, $months, $manualInputs);
            $weight = (float) $factor->weight;
            $weighted += $weight * $sub;
            $weights += $weight;
            $breakdown[] = [
                'factor_id' => (string) $factor->id,
                'code' => $factor->code,
                'name' => $factor->name,
                'source' => $factor->source,
                'weight' => $factor->weight,
                'raw_value' => $raw,
                'sub_score' => number_format($sub, 2, '.', ''),
                'contribution' => number_format($weights > 0.0 ? $weight * $sub : 0.0, 2, '.', ''),
            ];
        }

        $score = round($weighted / $weights, 2);
        $band = $this->bandFor($tenantId, $score);

        return CreditScore::create([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'score' => number_format($score, 2, '.', ''),
            'rating_band_id' => $band->id,
            'rating' => $band->code,
            'risk_level' => $band->risk_level,
            'breakdown' => $breakdown,
            'scored_on' => $today->toDateString(),
            'scored_by' => $userId,
            'notes' => $notes,
        ])->refresh();
    }

    /**
     * The credit limit the latest score of the customer supports: a share of the turnover of the last twelve months
     * divided by four, capped by the ceiling of the band and rounded down to the hundred.
     *
     * @return array{score_id: string, rating: string, score: string, risk_level: string, quarterly_turnover: string, proposed_limit: string, current_limit: string}
     */
    public function proposeLimit(string $tenantId, string $customerId): array
    {
        $this->assertCustomer($tenantId, $customerId);

        $score = CreditScore::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->orderByDesc('scored_on')->orderByDesc('created_at')->first();
        $band = $score?->rating_band_id !== null ? CreditRatingBand::query()->where('tenant_id', $tenantId)->find($score->rating_band_id) : null;

        if ($score === null || $band === null) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.score.missing')]]);
        }

        $today = Carbon::today();
        $turnover = $this->receivables->invoicedBetween($tenantId, $customerId, $today->copy()->subYear()->addDay()->toDateString(), $today->toDateString());
        $quarterly = Decimal::round2(Decimal::div($turnover, '4'));
        $raw = Decimal::mul($quarterly, (string) $band->limit_multiplier);
        $capped = Decimal::compare($raw, (string) $band->limit_ceiling) > 0 ? (string) $band->limit_ceiling : $raw;
        $proposed = number_format(floor((float) $capped / 100) * 100, 2, '.', '');

        return [
            'score_id' => (string) $score->id,
            'rating' => $score->rating,
            'score' => $score->score,
            'risk_level' => $score->risk_level,
            'quarterly_turnover' => $quarterly,
            'proposed_limit' => $proposed,
            'current_limit' => $this->receivables->creditLimit($tenantId, $customerId),
        ];
    }

    /**
     * The latest score of the customer, if any.
     */
    public function latest(string $tenantId, string $customerId): ?CreditScore
    {
        return CreditScore::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->orderByDesc('scored_on')->orderByDesc('created_at')->first();
    }

    /**
     * @param  array<string, mixed>  $exposure
     * @param  array<string, int|float|string>  $manualInputs
     * @return array{0: string|null, 1: float}
     */
    private function subScore(CreditScorecardFactor $factor, array $exposure, int $months, array $manualInputs): array
    {
        switch ($factor->source) {
            case CreditScorecardFactor::OverdueRatio:
                $total = (float) $exposure['open_invoices'];
                $ratio = $total > 0.0 ? min(1.0, (float) $exposure['overdue_amount'] / $total) : 0.0;

                return [number_format($ratio * 100, 2, '.', ''), round(100 * (1 - $ratio), 2)];
            case CreditScorecardFactor::MaxDaysOverdue:
                $days = (int) $exposure['max_days_overdue'];

                return [(string) $days, round(max(0.0, 100 * (1 - $days / 90)), 2)];
            case CreditScorecardFactor::LimitUtilisation:
                if ($exposure['utilisation_percent'] === null) {
                    return [null, 50.0];
                }

                $utilisation = (float) $exposure['utilisation_percent'];

                return [number_format($utilisation, 2, '.', ''), round($utilisation <= 70 ? 100.0 : max(0.0, 100 * (1 - ($utilisation - 70) / 80)), 2)];
            case CreditScorecardFactor::RelationshipMonths:
                return [(string) $months, round(min(100.0, $months / 36 * 100), 2)];
            default:
                $input = $manualInputs[$factor->code] ?? null;

                if (! is_numeric($input) || (float) $input < 0 || (float) $input > 100) {
                    throw ValidationException::withMessages(['manual_inputs.'.$factor->code => [__('credit_errors.scorecard.manual_missing', ['code' => $factor->code])]]);
                }

                return [number_format((float) $input, 2, '.', ''), round((float) $input, 2)];
        }
    }

    private function relationshipMonths(string $tenantId, string $customerId, Carbon $today): int
    {
        $first = $this->receivables->firstInvoiceDate($tenantId, $customerId);

        return $first === null ? 0 : (int) Carbon::parse($first)->diffInMonths($today, true);
    }

    private function bandFor(string $tenantId, float $score): CreditRatingBand
    {
        $band = CreditRatingBand::query()
            ->where('tenant_id', $tenantId)
            ->where('min_score', '<=', $score)
            ->where('max_score', '>=', $score)
            ->orderByDesc('min_score')
            ->first();

        if ($band === null) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.scorecard.no_band', ['score' => number_format($score, 2, '.', '')])]]);
        }

        return $band;
    }

    private function assertCustomer(string $tenantId, string $customerId): void
    {
        if ($this->receivables->customerName($tenantId, $customerId) === null) {
            throw ValidationException::withMessages(['customer_id' => [__('credit_errors.customer.missing')]]);
        }
    }
}
