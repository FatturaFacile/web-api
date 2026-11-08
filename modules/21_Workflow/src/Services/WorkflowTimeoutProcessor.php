<?php

declare(strict_types=1);

namespace Modules\Workflow\Services;

use Modules\Workflow\Models\WorkflowInstanceStep;
use Throwable;

/**
 * The periodic pass over pending steps (every fifteen minutes per tenant): steps past their time limit are decided
 * automatically or timed out, and steps with an escalation rule are reminded and escalated on their own schedule.
 *
 * One step that fails (a record deleted under it, a bad assignee) is reported and skipped; it never stops the others.
 * Reminder and escalation times count in business hours of the calendar of the node, like the time limit itself.
 */
final readonly class WorkflowTimeoutProcessor
{
    private const int BatchSize = 500;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private WorkflowEngineService $engine,
        private BusinessCalendarCalculationService $calendar,
    ) {}

    /**
     * @return array{timeouts: int, auto_decisions: int, escalations: int, reminders: int}
     */
    public function run(): array
    {
        $counts = ['timeouts' => 0, 'auto_decisions' => 0, 'escalations' => 0, 'reminders' => 0];

        $due = WorkflowInstanceStep::where('status', 'pending')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->with('node.escalationRule')
            ->orderBy('due_at')
            ->limit(self::BatchSize)
            ->get();

        foreach ($due as $step) {
            $this->guard(function () use ($step, &$counts): void {
                $step->refresh();

                if ($step->status !== 'pending') {
                    return;
                }

                $action = $step->node?->escalationRule?->auto_action;

                if (in_array($action, ['auto_approve', 'auto_reject'], true)) {
                    $this->engine->recordSystemDecision($step, $action === 'auto_approve' ? 'approved' : 'rejected', "Automatic {$action} after the time limit.");
                    $counts['auto_decisions']++;

                    return;
                }

                $this->engine->handleStepTimeout($step);
                $counts['timeouts']++;
            });
        }

        $scheduled = WorkflowInstanceStep::where('status', 'pending')
            ->where(static fn ($query) => $query->whereNull('due_at')->orWhere('due_at', '>', now()))
            ->whereHas('node', static fn ($query) => $query->whereNotNull('escalation_rule_id'))
            ->with('node.escalationRule', 'node.calendar')
            ->orderBy('created_at')
            ->limit(self::BatchSize)
            ->get();

        foreach ($scheduled as $step) {
            $rule = $step->node?->escalationRule;

            if ($rule === null) {
                continue;
            }

            $this->guard(function () use ($step, $rule, &$counts): void {
                if ($step->reminded_at === null && $this->calendar->calculateDeadline($step->created_at, (int) $rule->reminder_hours, $step->node->calendar)->lte(now()) && $this->engine->remindStep($step)) {
                    $counts['reminders']++;
                }

                if ($step->escalated_at === null && $this->calendar->calculateDeadline($step->created_at, (int) $rule->escalation_hours, $step->node->calendar)->lte(now()) && $this->engine->escalateStep($step, $rule)) {
                    $counts['escalations']++;
                }
            });
        }

        return $counts;
    }

    /**
     * @param  callable(): void  $work
     */
    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
