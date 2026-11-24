<?php

declare(strict_types=1);

namespace Modules\AgentCommissions\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\AgentCommissions\Models\AgentProfile;
use Modules\AgentCommissions\Models\CommissionRule;

/**
 * Picks the commission rule of an invoice line. A rule matches when each of its dimensions (product category,
 * customer, zone) is empty or equals the line, it is active and inside its validity window, and it belongs to the agent
 * or to everybody. Among the matching rules the winner is deterministic:
 *
 *  1. lowest `priority` number,
 *  2. highest specificity (customer 4 + product category 2 + zone 1),
 *  3. rule of the agent over a general rule,
 *  4. lowest id.
 *
 * No matching rule means the fallback of the agent: the default rate of the profile, then the default rate of the sales
 * representative (handled by the caller).
 */
final class RuleResolver
{
    /** @var array<string, Collection<int, CommissionRule>> */
    private array $rules = [];

    public function flush(): void
    {
        $this->rules = [];
    }

    /**
     * @param  list<string>  $zoneIds
     */
    public function resolve(AgentProfile $profile, ?string $customerId, ?string $categoryId, array $zoneIds, CarbonInterface $on): ?CommissionRule
    {
        $matching = $this->rulesOf($profile)->filter(static function (CommissionRule $rule) use ($customerId, $categoryId, $zoneIds, $on): bool {
            if ($rule->customer_id !== null && $rule->customer_id !== $customerId) {
                return false;
            }

            if ($rule->product_category_id !== null && $rule->product_category_id !== $categoryId) {
                return false;
            }

            if ($rule->zone_id !== null && ! in_array($rule->zone_id, $zoneIds, true)) {
                return false;
            }

            if ($rule->valid_from !== null && $on->lt($rule->valid_from->startOfDay())) {
                return false;
            }

            return $rule->valid_to === null || $on->lte($rule->valid_to->endOfDay());
        });

        return $matching->sort(static fn (CommissionRule $a, CommissionRule $b): int => [(int) $a->priority, -self::specificity($a), $a->agent_profile_id === null ? 1 : 0, (string) $a->id]
            <=> [(int) $b->priority, -self::specificity($b), $b->agent_profile_id === null ? 1 : 0, (string) $b->id])->first();
    }

    public static function specificity(CommissionRule $rule): int
    {
        return ($rule->customer_id !== null ? 4 : 0) + ($rule->product_category_id !== null ? 2 : 0) + ($rule->zone_id !== null ? 1 : 0);
    }

    /**
     * @return Collection<int, CommissionRule>
     */
    private function rulesOf(AgentProfile $profile): Collection
    {
        return $this->rules[(string) $profile->id] ??= CommissionRule::query()
            ->with('tiers')
            ->where('is_active', true)
            ->where(static fn ($query) => $query->whereNull('agent_profile_id')->orWhere('agent_profile_id', $profile->id))
            ->get();
    }
}
