<?php

declare(strict_types=1);

namespace Modules\CRM\Services\Intake;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Collection;
use Modules\CRM\Contracts\LeadScoringServiceInterface;
use Modules\CRM\DTOs\ScoreResult;
use Modules\CRM\Events\LeadScoreChangedEvent;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;
use Modules\CRM\Models\LeadScoringRule;
use Modules\CRM\Models\LeadSetting;

/**
 * Rule-driven lead scoring: demographic rules match lead attributes once, behavioural rules add points for
 * each recent event (within decay_days, up to max_applications). The total is clamped to 0-100.
 */
final readonly class LeadScoringEngine implements LeadScoringServiceInterface
{
    private const array FREE_EMAIL_PROVIDERS = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'libero.it', 'icloud.com'];

    public function __construct(
        private EventDispatcher $dispatcher,
    ) {}

    public function evaluate(Lead $lead): ScoreResult
    {
        $tenantId = (string) $lead->tenant_id;
        $settings = LeadSetting::forTenant($tenantId);

        /** @var Collection<int, LeadScoringRule> $rules */
        $rules = LeadScoringRule::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('category')
            ->where('is_active', true)
            ->orderBy('category')
            ->orderBy('code')
            ->get();

        if ($rules->isEmpty()) {
            $score = $this->legacyScore($lead);

            return new ScoreResult($score, $settings->ratingFor($score), [
                ['rule_id' => null, 'code' => 'legacy_heuristic', 'points' => $score, 'applications' => 1],
            ]);
        }

        $events = $lead->exists ? $this->loadEvents($lead, $rules) : collect();

        $total = 0;
        $breakdown = [];

        foreach ($rules as $rule) {
            $applications = $rule->category === 'behavioral'
                ? $this->behavioralApplications($rule, $events)
                : ($this->demographicMatches($rule, $lead) ? 1 : 0);

            if ($applications === 0) {
                continue;
            }

            $points = $applications * (int) $rule->points;
            $total += $points;
            $breakdown[] = [
                'rule_id' => (string) $rule->id,
                'code' => (string) $rule->code,
                'points' => $points,
                'applications' => $applications,
            ];
        }

        $score = max(0, min(100, $total));

        return new ScoreResult($score, $settings->ratingFor($score), $breakdown);
    }

    public function rescore(Lead $lead): Lead
    {
        $result = $this->evaluate($lead);
        $previousScore = (int) $lead->score;
        $previousRating = $lead->rating !== null ? (string) $lead->rating : null;

        $lead->forceFill([
            'score' => $result->score,
            'rating' => $result->rating,
            'score_breakdown' => $result->breakdown,
            'scored_at' => now(),
        ])->save();

        if ($previousScore !== $result->score || $previousRating !== $result->rating) {
            $event = new LeadScoreChangedEvent(
                leadId: (string) $lead->id,
                previousScore: $previousScore,
                score: $result->score,
                previousRating: $previousRating,
                rating: $result->rating,
                tenantId: (string) $lead->tenant_id,
                companyId: (string) $lead->tenant_id,
            );
            $this->dispatcher->publish($event);
            event($event);
        }

        return $lead;
    }

    public function legacyScore(Lead $lead): int
    {
        $score = 0;

        $title = strtolower((string) $lead->title);
        if (preg_match('/(director|vp|c-level|ceo|cfo|cto|coo|head|chief|president|founder|owner|partner|manager)/', $title)) {
            $score += 20;
        }

        $email = strtolower((string) $lead->email);
        if (! empty($email)) {
            $domain = substr(strrchr($email, '@') ?: '', 1);
            if (! in_array($domain, self::FREE_EMAIL_PROVIDERS, true) && ! empty($domain)) {
                $score += 15;
            } else {
                $score += 5;
            }
        }

        if (! empty($lead->phone)) {
            $score += 10;
        }

        if (! empty($lead->company_name)) {
            $score += 15;
        }

        $budget = (float) $lead->estimated_budget;
        if ($budget >= 50000.0) {
            $score += 30;
        } elseif ($budget >= 10000.0) {
            $score += 20;
        } elseif ($budget > 0.0) {
            $score += 10;
        }

        if (! empty($lead->website)) {
            $score += 10;
        }

        return min(100, $score);
    }

    /**
     * @param  Collection<int, LeadScoringRule>  $rules
     * @return Collection<int, LeadActivityEvent>
     */
    private function loadEvents(Lead $lead, Collection $rules): Collection
    {
        $types = $rules->where('category', 'behavioral')->pluck('event_type')->filter()->unique()->values()->all();

        if ($types === []) {
            return collect();
        }

        return LeadActivityEvent::query()
            ->where('tenant_id', $lead->tenant_id)
            ->where('lead_id', $lead->id)
            ->whereIn('type', $types)
            ->get();
    }

    /**
     * @param  Collection<int, LeadActivityEvent>  $events
     */
    private function behavioralApplications(LeadScoringRule $rule, Collection $events): int
    {
        $cutoff = $rule->decay_days !== null ? now()->subDays((int) $rule->decay_days) : null;

        $count = $events
            ->filter(function (LeadActivityEvent $event) use ($rule, $cutoff): bool {
                if ($event->type !== $rule->event_type) {
                    return false;
                }
                if ($cutoff !== null && ($event->occurred_at === null || $event->occurred_at->lt($cutoff))) {
                    return false;
                }
                if (! empty($rule->field)) {
                    $metadata = is_array($event->metadata) ? $event->metadata : [];

                    return $this->compare(data_get($metadata, (string) $rule->field), (string) $rule->operator, $rule->value);
                }

                return true;
            })
            ->count();

        if ($rule->max_applications !== null) {
            $count = min($count, (int) $rule->max_applications);
        }

        return $count;
    }

    private function demographicMatches(LeadScoringRule $rule, Lead $lead): bool
    {
        if (empty($rule->field)) {
            return false;
        }

        return $this->compare($this->leadValue($lead, (string) $rule->field), (string) $rule->operator, $rule->value);
    }

    private function leadValue(Lead $lead, string $field): mixed
    {
        if ($field === 'email_domain') {
            $email = (string) $lead->email;

            return str_contains($email, '@') ? substr((string) strrchr($email, '@'), 1) : null;
        }

        if (! in_array($field, LeadScoringRule::DEMOGRAPHIC_FIELDS, true)) {
            return null;
        }

        return $lead->getAttribute($field);
    }

    /**
     * Evaluates one rule condition. Missing values never match, except for "exists" with a false value.
     */
    public function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        $isBlank = $actual === null || (is_string($actual) && trim($actual) === '') || (is_array($actual) && $actual === []);

        if ($operator === 'exists') {
            $shouldExist = $expected === null || filter_var($expected, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;

            return $shouldExist ? ! $isBlank : $isBlank;
        }

        if ($isBlank || is_array($actual)) {
            return false;
        }

        $expectedList = is_array($expected) ? array_values($expected) : [$expected];

        return match ($operator) {
            'eq' => $this->equals($actual, $expected),
            'in' => array_any($expectedList, fn (mixed $item): bool => $this->equals($actual, $item)),
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'between' => $this->between($actual, $expected),
            'contains' => array_any(
                $expectedList,
                fn (mixed $item): bool => is_scalar($item) && (string) $item !== '' && str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $item)),
            ),
            default => false,
        };
    }

    private function equals(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected) || ! is_scalar($actual) || $expected === null) {
            return false;
        }

        if (is_numeric($actual) && is_numeric($expected)) {
            return abs((float) $actual - (float) $expected) < 0.000001;
        }

        return mb_strtolower(trim((string) $actual)) === mb_strtolower(trim((string) $expected));
    }

    private function between(mixed $actual, mixed $expected): bool
    {
        if (! is_numeric($actual) || ! is_array($expected)) {
            return false;
        }

        $min = $expected['min'] ?? $expected[0] ?? null;
        $max = $expected['max'] ?? $expected[1] ?? null;

        if ($min !== null && (! is_numeric($min) || (float) $actual < (float) $min)) {
            return false;
        }

        if ($max !== null && (! is_numeric($max) || (float) $actual > (float) $max)) {
            return false;
        }

        return $min !== null || $max !== null;
    }
}
