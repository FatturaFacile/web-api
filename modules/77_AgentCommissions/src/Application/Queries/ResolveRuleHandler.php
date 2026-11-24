<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Application\Queries;

use Carbon\CarbonImmutable;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\CommissionRule;
use Modules\AgentCommissions\Services\RuleResolver;
use Modules\AgentCommissions\Services\ZoneResolver;

/**
 * Simulator of the rule precedence: which rule would win for a customer / product category / date, or the fallback rate.
 */
final class ResolveRuleHandler
{
    public function __construct(
        private readonly RuleResolver $rules,
        private readonly ZoneResolver $zones,
    ) {}

    /**
     * @return array{rule: CommissionRule|null, fallback_rate: string|null, zone_ids: list<string>}
     */
    public function handle(ResolveRuleQuery $query): array
    {
        $profile = AgentProfile::query()->with('representative')->findOrFail($query->agentProfileId);
        $zoneIds = $this->zones->zoneIdsFor($query->customerId);
        $rule = $this->rules->resolve($profile, $query->customerId, $query->productCategoryId, $zoneIds, CarbonImmutable::parse($query->date));

        $fallback = $profile->default_rate;

        if ($fallback === null) {
            $fallback = $profile->representative?->default_commission_rate;
        }

        return [
            'rule' => $rule,
            'fallback_rate' => $rule === null ? (string) ($fallback ?? '0') : null,
            'zone_ids' => $zoneIds,
        ];
    }
}
