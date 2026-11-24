<?php

declare(strict_types=1);

namespace Modules\Invoicing\Services\Receivables;

use App\Models\Tenant;
use App\Services\Tenancy\TenantClock;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\CustomerCreditLimit;
use Modules\Invoicing\Models\CustomerCreditOverride;
use Modules\Invoicing\Models\CustomerCreditProfile;

/**
 * The credit check the order confirmation (Mod 037) and the delivery note issue (Mod 040) ask before they go on. It
 * compares the exposure of the customer (plus what the operation adds) with the limit in force, applies the reaction
 * policy of the limit to the breach, and lets an active override through.
 *
 * Reaction policies: `warning` never blocks; `block_order` blocks the order confirmation (a delivery note only
 * warns); `block_delivery_note` blocks the issue of the delivery note (an order only warns); `credit_hold` blocks both.
 */
final class CreditEnforcementService
{
    public const string ScopeOrder = 'order';

    public const string ScopeDeliveryNote = 'delivery_note';

    public function __construct(
        private readonly CreditExposureService $exposure,
        private readonly CreditLimitService $limits,
        private readonly TenantClock $clock,
    ) {}

    /**
     * @param  string  $additional  value the operation adds to the exposure (the order total; a delivery note of an order adds nothing, the order is counted already)
     */
    public function evaluate(string $tenantId, string $customerId, string $scope, string $additional, ?string $excludeOrderId = null): CreditDecision
    {
        $today = $this->clock->today(Tenant::query()->find($tenantId));
        $limit = $this->limits->effective($tenantId, $customerId, $today);
        $profile = CustomerCreditProfile::query()->where('tenant_id', $tenantId)->where('customer_id', $customerId)->first();
        $onHold = (bool) ($profile->is_credit_hold ?? false);

        if ($limit === null && ! $onHold) {
            return new CreditDecision(CreditDecision::Ok, [], null, '0.00', '0.00');
        }

        $figures = $this->exposure->calculate($tenantId, $customerId, $today, $excludeOrderId);
        $projected = Decimal::round2(Decimal::add($figures['total_exposure'], $additional));
        $granted = $limit !== null ? (string) $limit->granted_limit : '0.00';
        $breaches = [];
        $reasons = [];

        if ($limit !== null && Decimal::isPositive($granted) && Decimal::compare($projected, $granted) > 0) {
            $breaches[] = 'over_limit';
            $reasons[] = __('receivables.reason_over_limit', ['exposure' => $projected, 'limit' => $granted]);
        }

        if ($limit !== null && $limit->max_overdue_days !== null
            && $figures['max_days_past_due'] > $limit->max_overdue_days
            && Decimal::compare($figures['overdue_amount'], (string) $limit->overdue_tolerance_amount) > 0) {
            $breaches[] = 'overdue_days';
            $reasons[] = __('receivables.reason_overdue', ['days' => $figures['max_days_past_due'], 'amount' => $figures['overdue_amount']]);
        }

        if ($onHold) {
            $breaches[] = 'credit_hold';
            $reasons[] = (string) ($profile->hold_reason ?: __('receivables.reason_credit_hold'));
        }

        $reaction = $limit instanceof CustomerCreditLimit ? (string) $limit->reaction_policy : CustomerCreditLimit::ReactionCreditHold;

        if ($breaches === []) {
            return new CreditDecision(CreditDecision::Ok, [], null, $projected, $granted, false, $reaction);
        }

        $policy = $reaction;
        $blocks = match ($policy) {
            CustomerCreditLimit::ReactionCreditHold => true,
            CustomerCreditLimit::ReactionBlockOrder => $scope === self::ScopeOrder,
            CustomerCreditLimit::ReactionBlockDeliveryNote => $scope === self::ScopeDeliveryNote,
            default => false,
        } || ($onHold && $scope === self::ScopeOrder);

        $outcome = $blocks ? CreditDecision::Block : CreditDecision::Warning;
        $reason = implode(' ', $reasons);

        if ($this->overrideCovers($tenantId, $customerId, $scope)) {
            return new CreditDecision(CreditDecision::Ok, $breaches, $reason, $projected, $granted, true, $policy);
        }

        return new CreditDecision($outcome, $breaches, $reason, $projected, $granted, false, $policy);
    }

    private function overrideCovers(string $tenantId, string $customerId, string $scope): bool
    {
        return CustomerCreditOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $customerId)
            ->active(now())
            ->get()
            ->contains(static fn (CustomerCreditOverride $override): bool => $override->covers($scope));
    }
}
