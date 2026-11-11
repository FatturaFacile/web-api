<?php

declare(strict_types=1);

namespace Modules\Quality\Services;

use Carbon\CarbonImmutable;
use Modules\Quality\Models\QmsAudit;
use Modules\Quality\Models\QmsCapaAction;
use Modules\Quality\Models\QmsNonconformity;
use Modules\Quality\Models\QmsProcedure;

/**
 * Indicators of the quality management system for the active company: open non-conformities by age, CAPA on time,
 * audits and procedures due. Ages count whole days from the detection date; an action is on time when it was completed
 * on or before its due date (the whole due day counts).
 */
final readonly class QualityKpiService
{
    /** Age buckets in days: label => [from, to] (to null: no upper bound). */
    public const array AgeBuckets = ['0-30' => [0, 30], '31-60' => [31, 60], '61-90' => [61, 90], '90+' => [91, null]];

    /**
     * @return array<string, mixed>
     */
    public function summary(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::now()->startOfDay();

        return [
            'as_of' => $today->toDateString(),
            'open_nonconformities' => $this->openNonconformities($today),
            'capa' => $this->capa($today),
            'audits' => [
                'planned' => QmsAudit::query()->where('status', 'planned')->count(),
                'in_progress' => QmsAudit::query()->where('status', 'in_progress')->count(),
                'overdue_planned' => QmsAudit::query()->where('status', 'planned')->whereDate('planned_date', '<', $today->toDateString())->count(),
            ],
            'procedures' => [
                'approved' => QmsProcedure::query()->where('status', 'approved')->count(),
                'pending_approval' => QmsProcedure::query()->where('status', 'pending_approval')->count(),
                'review_overdue' => QmsProcedure::query()->where('status', 'approved')->whereNotNull('review_due_date')->whereDate('review_due_date', '<', $today->toDateString())->count(),
            ],
        ];
    }

    /**
     * @return array{total: int, by_age: array<string, int>, by_source: array<string, int>, by_severity: array<string, int>, oldest_days: int|null}
     */
    private function openNonconformities(CarbonImmutable $today): array
    {
        $open = QmsNonconformity::query()->whereIn('status', QmsNonconformity::OpenStatuses)->get(['id', 'source', 'severity', 'detected_at']);

        $byAge = array_fill_keys(array_keys(self::AgeBuckets), 0);
        $ages = [];
        foreach ($open as $nonconformity) {
            $age = max(0, (int) $nonconformity->detected_at->startOfDay()->diffInDays($today));
            $ages[] = $age;
            foreach (self::AgeBuckets as $label => [$from, $to]) {
                if ($age >= $from && ($to === null || $age <= $to)) {
                    $byAge[$label]++;

                    break;
                }
            }
        }

        $bySource = array_fill_keys(QmsNonconformity::Sources, 0);
        $bySeverity = array_fill_keys(QmsNonconformity::Severities, 0);
        foreach ($open as $nonconformity) {
            $bySource[$nonconformity->source]++;
            $bySeverity[$nonconformity->severity]++;
        }

        return ['total' => $open->count(), 'by_age' => $byAge, 'by_source' => $bySource, 'by_severity' => $bySeverity, 'oldest_days' => $ages === [] ? null : max($ages)];
    }

    /**
     * @return array{total: int, open: int, overdue: int, completed: int, completed_on_time: int, completed_late: int, on_time_rate: float|null, ineffective: int}
     */
    private function capa(CarbonImmutable $today): array
    {
        $actions = QmsCapaAction::query()->where('status', '!=', 'cancelled')->get(['id', 'status', 'due_date', 'completed_at']);

        $open = 0;
        $overdue = 0;
        $completed = 0;
        $onTime = 0;
        $ineffective = 0;

        foreach ($actions as $action) {
            if (in_array($action->status, QmsCapaAction::OpenStatuses, true)) {
                $open++;
                if ($action->due_date->toDateString() < $today->toDateString()) {
                    $overdue++;
                }

                continue;
            }

            $completed++;
            if ($action->status === 'ineffective') {
                $ineffective++;
            }

            if ($action->completed_at !== null && $action->completed_at->lte($action->due_date->endOfDay())) {
                $onTime++;
            }
        }

        $measured = $completed + $overdue;

        return [
            'total' => $actions->count(),
            'open' => $open,
            'overdue' => $overdue,
            'completed' => $completed,
            'completed_on_time' => $onTime,
            'completed_late' => $completed - $onTime,
            'on_time_rate' => $measured === 0 ? null : round($onTime / $measured * 100, 1),
            'ineffective' => $ineffective,
        ];
    }
}
