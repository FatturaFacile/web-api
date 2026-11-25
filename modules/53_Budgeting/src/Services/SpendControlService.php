<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Modules\Budgeting\Domain\Availability;
use Modules\Budgeting\Domain\SpendDecision;
use Modules\Budgeting\Domain\SpendRequest;
use Modules\Budgeting\Exceptions\SpendLockException;
use Modules\Budgeting\Models\BudgetCommitment;
use Modules\Budgeting\Models\BudgetOverride;
use Modules\Budgeting\Models\BudgetSpendCheck;
use Modules\Budgeting\Models\BudgetSpendRule;

/**
 * The spend lock. A request is compared with the budget of its scope: spend so far (actual costs plus open
 * commitments) plus the amount asked must stay within the threshold of the budget. Over it, the rule decides: allow,
 * allow with a warning, or block unless an approved override covers the excess. A scope without budget is not
 * controlled unless the rule says so. Warnings, blocks and overrides are logged; a plain pass is not.
 */
final readonly class SpendControlService
{
    public function __construct(
        private AvailabilityService $availability,
        private SpendRuleService $rules,
        private OverrideService $overrides,
    ) {}

    /**
     * Evaluates a request without enforcing it (the "what if" of the API and the screens when `$record` is false).
     */
    public function evaluate(SpendRequest $request, bool $record = true): SpendDecision
    {
        $rule = $this->rules->resolve($request->tenantId, $request->sourceType, $request->stage, $request->accountId, $request->costCenterId, $request->projectId);
        $uncontrolled = $rule === null || $rule->mode === BudgetSpendRule::Allow;

        // Nothing to enforce: skip the ledger and commitment queries unless the caller wants the figures.
        if ($uncontrolled && $record) {
            return new SpendDecision(SpendDecision::Ok, Availability::unchecked($request->date->year), $request->amount, '0.00', $rule?->id, $rule?->mode, null);
        }

        $availability = $this->availability->forScope($request->tenantId, $request->date, $request->accountId, $request->costCenterId, $request->projectId, $request->sourceType, $request->sourceId, $request->replacesRequisitionId, $request->amount);

        if ($uncontrolled) {
            return new SpendDecision(SpendDecision::Ok, $availability, $request->amount, '0.00', $rule?->id, $rule?->mode, null);
        }

        $shortfall = $this->shortfall($availability, $request->amount, (string) $rule->threshold_percent, $rule->enforce_without_budget);

        if (bccomp($shortfall, '0', 2) <= 0) {
            return new SpendDecision(SpendDecision::Ok, $availability, $request->amount, '0.00', $rule->id, $rule->mode, null);
        }

        $override = $rule->mode === BudgetSpendRule::HardBlock ? $this->overrides->coverageFor($request, $shortfall) : null;
        $outcome = match (true) {
            $rule->mode === BudgetSpendRule::Warning => SpendDecision::Warning,
            $override !== null => SpendDecision::Overridden,
            default => SpendDecision::Blocked,
        };

        $decision = new SpendDecision($outcome, $availability, $request->amount, $shortfall, $rule->id, $rule->mode, $override?->id);

        return $record ? $decision->withCheck($this->log($request, $decision)->id) : $decision;
    }

    /**
     * Evaluates and enforces: a blocked request raises the lock exception.
     *
     * @throws SpendLockException
     */
    public function enforce(SpendRequest $request): SpendDecision
    {
        $decision = $this->evaluate($request);

        if ($decision->isBlocked()) {
            throw new SpendLockException(__('budgeting_errors.spend.blocked', [
                'available' => $decision->availability->available(),
                'requested' => $decision->requested,
                'currency' => $decision->availability->currency,
            ]), $decision);
        }

        return $decision;
    }

    /**
     * A document got its commitment: the override that let it through is used up. A document override is consumed
     * whole, an allowance by the part of the document that went over the budget.
     */
    public function settle(BudgetCommitment $commitment, ?string $userId): void
    {
        $request = new SpendRequest($commitment->tenant_id, $commitment->source_type, $commitment->source_id, $commitment->source_number, BudgetSpendRule::StageApprove, '0.00', $commitment->account_id, $commitment->cost_center_id, $commitment->project_id, $commitment->commitment_date, $userId);
        $rule = $this->rules->resolve($commitment->tenant_id, $commitment->source_type, BudgetSpendRule::StageApprove, $commitment->account_id, $commitment->cost_center_id, $commitment->project_id)
            ?? $this->rules->resolve($commitment->tenant_id, $commitment->source_type, BudgetSpendRule::StageSubmit, $commitment->account_id, $commitment->cost_center_id, $commitment->project_id);

        if ($rule === null || $rule->mode !== BudgetSpendRule::HardBlock) {
            return;
        }

        $availability = $this->availability->forScope($commitment->tenant_id, $commitment->commitment_date, $commitment->account_id, $commitment->cost_center_id, $commitment->project_id);
        $overrun = $this->shortfall($availability, '0.00', (string) $rule->threshold_percent, false);

        if (bccomp($overrun, '0', 2) <= 0) {
            return;
        }

        $amount = bccomp($overrun, $commitment->openAmount(), 2) > 0 ? $commitment->openAmount() : $overrun;
        $override = $this->overrides->coverageFor($request, $amount);

        if ($override !== null) {
            $this->overrides->consume($override, $amount, $userId);
        }
    }

    /**
     * What a request takes beyond the threshold of the budget: (actual + commitments + requested) - budget x threshold.
     * Without budget the whole request is the excess when the rule enforces that case.
     */
    private function shortfall(Availability $availability, string $requested, string $thresholdPercent, bool $enforceWithoutBudget): string
    {
        if (! $availability->hasBudget) {
            return $enforceWithoutBudget ? $requested : '0.00';
        }

        $limit = bcmul($availability->budget, bcdiv($thresholdPercent, '100', 8), 2);
        $projected = bcadd(bcadd($availability->actual, $availability->committed, 2), $requested, 2);
        $excess = bcsub($projected, $limit, 2);

        return bccomp($excess, '0', 2) < 0 ? '0.00' : $excess;
    }

    private function log(SpendRequest $request, SpendDecision $decision): BudgetSpendCheck
    {
        $availability = $decision->availability;
        $projected = bcadd(bcadd($availability->actual, $availability->committed, 2), $request->amount, 2);
        $override = $decision->overrideId !== null ? BudgetOverride::query()->find($decision->overrideId) : null;

        return BudgetSpendCheck::create([
            'tenant_id' => $request->tenantId,
            'source_type' => $request->sourceType,
            'source_id' => $request->sourceId,
            'source_number' => $request->sourceNumber,
            'stage' => $request->stage,
            'outcome' => $decision->outcome === SpendDecision::Overridden ? BudgetSpendCheck::Overridden : ($decision->isBlocked() ? BudgetSpendCheck::Blocked : BudgetSpendCheck::Warning),
            'rule_id' => $decision->ruleId,
            'override_id' => $override?->id,
            'account_id' => $request->accountId,
            'cost_center_id' => $request->costCenterId,
            'project_id' => $request->projectId,
            'fiscal_year' => $availability->fiscalYear,
            'budget_amount' => $availability->budget,
            'actual_amount' => $availability->actual,
            'committed_amount' => $availability->committed,
            'requested_amount' => $request->amount,
            'available_amount' => $availability->available(),
            'projected_percent' => bccomp($availability->budget, '0', 2) > 0 ? bcround(bcmul(bcdiv($projected, $availability->budget, 8), '100', 8), 2) : null,
            'user_id' => $request->userId,
            'message' => __('budgeting_errors.spend.'.$decision->outcome, ['available' => $availability->available(), 'requested' => $request->amount, 'currency' => $availability->currency]),
        ]);
    }
}
