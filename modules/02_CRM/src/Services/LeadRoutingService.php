<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadRoutingRule;

/**
 * Picks the sales rep for a lead: the first active rule (by priority) whose criteria match wins and rotates
 * round-robin across its team. Without a matching rule the lead is not routed (null).
 */
final class LeadRoutingService
{
    public function resolveAssignee(Lead $lead): ?string
    {
        $rules = LeadRoutingRule::where('tenant_id', $lead->tenant_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('created_at')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->matches($rule, $lead)) {
                continue;
            }

            $assignee = $this->nextAssignee($rule);
            if ($assignee !== null) {
                return $assignee;
            }
        }

        return null;
    }

    public function matches(LeadRoutingRule $rule, Lead $lead): bool
    {
        $c = $rule->criteria ?? [];

        $lower = static fn (array $values): array => array_map('strtolower', array_map('strval', $values));

        return match ($rule->strategy) {
            'geographic' => $this->inList($lead->region, $lower($c['regions'] ?? []))
                || $this->inList($lead->country, $lower($c['countries'] ?? [])),
            'product_line' => $this->inList($lead->product_line, $lower($c['product_lines'] ?? [])),
            'company_size' => $lead->company_size !== null
                && $lead->company_size >= (int) ($c['min_size'] ?? 0)
                && $lead->company_size <= (int) ($c['max_size'] ?? PHP_INT_MAX),
            default => true, // round_robin: catch-all
        };
    }

    /**
     * Atomically advances the rotation cursor of the rule and returns the next active team member.
     */
    private function nextAssignee(LeadRoutingRule $rule): ?string
    {
        return DB::transaction(function () use ($rule): ?string {
            $locked = LeadRoutingRule::where('id', $rule->id)->lockForUpdate()->firstOrFail();

            $team = array_values((array) $locked->assignee_user_ids);
            $active = User::whereIn('id', $team)->where('is_active', true)->pluck('id')->map(fn ($id) => (string) $id)->all();
            $team = array_values(array_filter($team, fn ($id): bool => in_array((string) $id, $active, true)));

            if ($team === []) {
                return null;
            }

            $cursor = (int) $locked->rotation_cursor;
            $locked->update(['rotation_cursor' => ($cursor + 1) % count($team)]);

            return (string) $team[$cursor % count($team)];
        });
    }

    /**
     * @param  array<int, string>  $list  lower-cased values
     */
    private function inList(?string $value, array $list): bool
    {
        return $value !== null && $value !== '' && in_array(strtolower($value), $list, true);
    }
}
