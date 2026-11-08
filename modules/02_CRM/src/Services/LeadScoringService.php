<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Illuminate\Support\Collection;
use Modules\CRM\Models\Activity;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadScoringRule;

/**
 * Lead scoring with an explanation. A tenant that defines active rules is scored only by them (field conditions and
 * activity engagement); a tenant without rules keeps the built-in profile model. Every evaluation returns the
 * breakdown of the points, which is stored on the lead so a salesperson can see why a lead is hot.
 */
final class LeadScoringService
{
    /**
     * Free mailbox providers: an address there says nothing about the company.
     */
    private const array FreeEmailProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'libero.it', 'icloud.com'];

    /**
     * Scores the lead without saving.
     *
     * @return array{score: int, rating: string, model: string, breakdown: list<array{rule: string, points: int, reason: string}>}
     */
    public function evaluate(Lead $lead): array
    {
        $rules = LeadScoringRule::where('tenant_id', $lead->tenant_id)
            ->where('is_active', true)
            ->orderBy('sequence')
            ->get();

        $breakdown = $rules->isEmpty() ? $this->defaultModel($lead) : $this->tenantModel($lead, $rules);
        $score = max(0, min(100, array_sum(array_column($breakdown, 'points'))));

        return [
            'score' => $score,
            'rating' => $this->rating($score),
            'model' => $rules->isEmpty() ? 'default' : 'tenant_rules',
            'breakdown' => $breakdown,
        ];
    }

    /**
     * Scores the lead and stores score, rating and explanation. Converted and disqualified leads are scored too: the
     * history stays explainable.
     *
     * @return array{score: int, rating: string, model: string, breakdown: list<array{rule: string, points: int, reason: string}>}
     */
    public function recalculate(Lead $lead): array
    {
        $result = $this->evaluate($lead);

        $lead->forceFill([
            'score' => $result['score'],
            'rating' => $result['rating'],
            'score_breakdown' => ['model' => $result['model'], 'items' => $result['breakdown']],
            'score_calculated_at' => now(),
        ])->save();

        return $result;
    }

    /**
     * Recalculates every lead of a tenant, used after the rules change.
     */
    public function recalculateTenant(string $tenantId): int
    {
        $count = 0;

        Lead::where('tenant_id', $tenantId)->orderBy('id')->chunkById(200, function (Collection $leads) use (&$count): void {
            foreach ($leads as $lead) {
                $this->recalculate($lead);
                $count++;
            }
        });

        return $count;
    }

    /**
     * hot from 70, warm from 40, cold below: the thresholds used since the first scoring model.
     */
    public function rating(int $score): string
    {
        return $score >= 70 ? 'hot' : ($score >= 40 ? 'warm' : 'cold');
    }

    /**
     * @param  Collection<int, LeadScoringRule>  $rules
     * @return list<array{rule: string, points: int, reason: string}>
     */
    private function tenantModel(Lead $lead, Collection $rules): array
    {
        $breakdown = [];
        $activities = null;

        foreach ($rules as $rule) {
            if ($rule->rule_type === LeadScoringRule::TypeActivity) {
                $activities ??= Activity::where('tenant_id', $lead->tenant_id)
                    ->where('related_type', 'lead')
                    ->where('related_id', $lead->id)
                    ->get(['type', 'status']);

                $matching = $activities
                    ->filter(fn (Activity $activity): bool => ($rule->activity_type === null || $activity->type === $rule->activity_type)
                        && ($rule->activity_status === null || $activity->status === $rule->activity_status))
                    ->count();

                if ($matching === 0) {
                    continue;
                }

                $points = $matching * $rule->points;
                if ($rule->max_points !== null) {
                    $points = $rule->points >= 0 ? min($points, $rule->max_points) : max($points, -abs($rule->max_points));
                }

                $breakdown[] = [
                    'rule' => (string) $rule->name,
                    'points' => $points,
                    'reason' => sprintf('%d %s activit%s', $matching, $rule->activity_type ?? 'logged', $matching === 1 ? 'y' : 'ies'),
                ];

                continue;
            }

            $value = $this->fieldValue($lead, (string) $rule->field);
            if ($this->matches($value, (string) $rule->operator, $rule->value)) {
                $breakdown[] = [
                    'rule' => (string) $rule->name,
                    'points' => (int) $rule->points,
                    'reason' => trim(sprintf('%s %s %s', $rule->field, $rule->operator, (string) $rule->value)),
                ];
            }
        }

        return $breakdown;
    }

    private function fieldValue(Lead $lead, string $field): mixed
    {
        return match ($field) {
            'lead_source_code' => $lead->leadSource?->code,
            default => in_array($field, LeadScoringRule::Fields, true) ? $lead->getAttribute($field) : null,
        };
    }

    private function matches(mixed $value, string $operator, ?string $expected): bool
    {
        $text = strtolower(trim((string) $value));
        $target = strtolower(trim((string) $expected));

        return match ($operator) {
            'filled' => $text !== '',
            'empty' => $text === '',
            'equals' => $text === $target,
            'not_equals' => $text !== $target,
            'contains' => $target !== '' && str_contains($text, $target),
            'in' => in_array($text, array_map(fn (string $item): string => strtolower(trim($item)), explode(',', $target)), true),
            'gte' => is_numeric($value) && is_numeric($expected) && (float) $value >= (float) $expected,
            'lte' => is_numeric($value) && is_numeric($expected) && (float) $value <= (float) $expected,
            'business_email' => $this->isBusinessEmail($text),
            default => false,
        };
    }

    private function isBusinessEmail(string $email): bool
    {
        $domain = substr(strrchr($email, '@') ?: '', 1);

        return $domain !== '' && ! in_array($domain, self::FreeEmailProviders, true);
    }

    /**
     * The built-in model: seniority, email quality, reachability, company, budget and website.
     *
     * @return list<array{rule: string, points: int, reason: string}>
     */
    private function defaultModel(Lead $lead): array
    {
        $breakdown = [];

        if (preg_match('/(director|vp|c-level|ceo|cfo|cto|coo|head|chief|president|founder|owner|partner|manager)/', strtolower((string) $lead->title)) === 1) {
            $breakdown[] = ['rule' => 'Senior job title', 'points' => 20, 'reason' => (string) $lead->title];
        }

        $email = strtolower((string) $lead->email);
        if ($email !== '') {
            $breakdown[] = $this->isBusinessEmail($email)
                ? ['rule' => 'Business email', 'points' => 15, 'reason' => $email]
                : ['rule' => 'Personal email', 'points' => 5, 'reason' => $email];
        }

        if (! empty($lead->phone)) {
            $breakdown[] = ['rule' => 'Phone available', 'points' => 10, 'reason' => (string) $lead->phone];
        }

        if (! empty($lead->company_name)) {
            $breakdown[] = ['rule' => 'Company known', 'points' => 15, 'reason' => (string) $lead->company_name];
        }

        $budget = (float) $lead->estimated_budget;
        if ($budget >= 50000.0) {
            $breakdown[] = ['rule' => 'Budget from 50,000', 'points' => 30, 'reason' => (string) $budget];
        } elseif ($budget >= 10000.0) {
            $breakdown[] = ['rule' => 'Budget from 10,000', 'points' => 20, 'reason' => (string) $budget];
        } elseif ($budget > 0.0) {
            $breakdown[] = ['rule' => 'Budget declared', 'points' => 10, 'reason' => (string) $budget];
        }

        if (! empty($lead->website)) {
            $breakdown[] = ['rule' => 'Website available', 'points' => 10, 'reason' => (string) $lead->website];
        }

        return $breakdown;
    }
}
