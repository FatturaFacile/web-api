<?php

declare(strict_types=1);

namespace Modules\Projects\Services;

use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Projects\Events\ProjectRetainerLowBalanceEvent;
use Modules\Projects\Models\Project;
use Modules\Projects\Models\ProjectBillingContract;
use Modules\Projects\Models\ProjectRetainerLedgerEntry;
use Modules\Projects\Models\ProjectTimesheet;
use Modules\Projects\Support\Money;
use Modules\Projects\Support\ProjectAuditTrail;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Prepaid retainer / hour bank (monte ore, canone a forfait) of a billing contract (Mod 081): the allowance plus the
 * top-ups, burned down by the approved billable hours of the project, oldest first. A timesheet is burned whole and only
 * while the balance covers it, so a timesheet the bank cannot cover stays to be billed as time and materials. When the
 * remaining balance falls to the low-balance threshold an alert event is published once, until a top-up lifts the
 * balance back above it.
 *
 * The bank is counted in hours when hours were prepaid, else in amount.
 */
final readonly class ProjectRetainerService
{
    public function __construct(
        private ProjectBillingService $billing,
        private EventDispatcher $dispatcher,
        private ProjectAuditTrail $audit,
    ) {}

    /**
     * @return array{unit: string, allowance_hours: string, allowance_amount: string, topped_up_hours: string, topped_up_amount: string, consumed_hours: string, consumed_amount: string, remaining_hours: string, remaining_amount: string, remaining_percent: string, low_balance_threshold_percent: string, is_low_balance: bool}
     */
    public function balance(ProjectBillingContract $contract): array
    {
        $entries = ProjectRetainerLedgerEntry::query()->where('billing_contract_id', $contract->id);
        $toppedHours = (string) (clone $entries)->where('entry_type', ProjectRetainerLedgerEntry::TopUp)->sum('hours');
        $toppedAmount = (string) (clone $entries)->where('entry_type', ProjectRetainerLedgerEntry::TopUp)->sum('amount');
        $usedHours = (string) (clone $entries)->where('entry_type', ProjectRetainerLedgerEntry::Consumption)->sum('hours');
        $usedAmount = (string) (clone $entries)->where('entry_type', ProjectRetainerLedgerEntry::Consumption)->sum('amount');

        $totalHours = Money::add($contract->prepaid_hours_allowance, $toppedHours);
        $totalAmount = Money::add($contract->prepaid_amount_allowance, $toppedAmount);
        $remainingHours = Money::sub($totalHours, $usedHours);
        $remainingAmount = Money::sub($totalAmount, $usedAmount);
        $byHours = Money::isPositive($totalHours);
        $percent = Money::percent(Money::div($byHours ? $remainingHours : $remainingAmount, $byHours ? $totalHours : $totalAmount)) ?? '0.00';

        return [
            'unit' => $byHours ? 'hours' : 'amount',
            'allowance_hours' => Money::round($contract->prepaid_hours_allowance, 2),
            'allowance_amount' => Money::amount($contract->prepaid_amount_allowance),
            'topped_up_hours' => Money::round($toppedHours, 2),
            'topped_up_amount' => Money::amount($toppedAmount),
            'consumed_hours' => Money::round($usedHours, 2),
            'consumed_amount' => Money::amount($usedAmount),
            'remaining_hours' => Money::round($remainingHours, 2),
            'remaining_amount' => Money::amount($remainingAmount),
            'remaining_percent' => $percent,
            'low_balance_threshold_percent' => Money::round($contract->low_balance_alert_threshold_percent, 2),
            'is_low_balance' => (Money::isPositive($totalHours) || Money::isPositive($totalAmount)) && Money::compare($percent, $contract->low_balance_alert_threshold_percent) <= 0,
        ];
    }

    /**
     * Prepaid hours or amount received from the customer.
     *
     * @throws ValidationException when nothing is topped up
     */
    public function topUp(Project $project, ProjectBillingContract $contract, string $hours, string $amount, string $date, ?string $note, string $actorUserId): ProjectRetainerLedgerEntry
    {
        if (! Money::isPositive($hours) && ! Money::isPositive($amount)) {
            throw ValidationException::withMessages(['hours' => [__('project_billing.errors.top_up_empty')]]);
        }

        return DB::transaction(function () use ($project, $contract, $hours, $amount, $date, $note, $actorUserId): ProjectRetainerLedgerEntry {
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->assertRetainer($contract);

            $before = $this->balance($contract);
            $entry = ProjectRetainerLedgerEntry::create([
                'tenant_id' => $project->tenant_id,
                'company_id' => $project->company_id,
                'project_id' => $project->id,
                'billing_contract_id' => $contract->id,
                'entry_type' => ProjectRetainerLedgerEntry::TopUp,
                'entry_date' => $date,
                'hours' => Money::round($hours, 4),
                'amount' => Money::round($amount, 4),
                'balance_hours_after' => Money::round(Money::add($before['remaining_hours'], $hours), 4),
                'balance_amount_after' => Money::round(Money::add($before['remaining_amount'], $amount), 4),
                'note' => $note,
                'created_by' => $actorUserId,
            ]);

            $after = $this->balance($contract);

            if ($contract->low_balance_alerted_at !== null && ! $after['is_low_balance']) {
                $contract->update(['low_balance_alerted_at' => null]);
            }

            $this->audit->record($project, $actorUserId, 'retainer_top_up', "Retainer topped up by {$entry->hours} h / {$entry->amount}", [], $after, $entry);

            return $entry;
        });
    }

    /**
     * Burns the approved billable hours up to the date against the bank.
     *
     * @return array{consumed_hours: string, consumed_amount: string, timesheet_ids: list<string>}
     */
    public function consume(Project $project, ProjectBillingContract $contract, string $upToDate, string $actorUserId): array
    {
        return DB::transaction(function () use ($project, $contract, $upToDate, $actorUserId): array {
            $contract = ProjectBillingContract::query()->whereKey($contract->id)->lockForUpdate()->firstOrFail();
            $this->assertRetainer($contract);

            $balance = $this->balance($contract);
            $byHours = $balance['unit'] === 'hours';
            $remainingHours = Money::of($balance['remaining_hours']);
            $remainingAmount = Money::of($balance['remaining_amount']);
            $burnedHours = Money::of(0);
            $burnedAmount = Money::of(0);
            $ids = [];

            $timesheets = $this->billing->unbilledTimesheets($project)
                ->whereDate('work_date', '<=', $upToDate)
                ->whereNotIn('id', $this->consumedTimesheetIds($contract))
                ->orderBy('work_date')->orderBy('id')->lockForUpdate()->get();

            /** @var ProjectTimesheet $sheet */
            foreach ($timesheets as $sheet) {
                $hours = Money::of($sheet->hours_worked);
                $charge = Money::of($sheet->total_charge);

                if (Money::compare($byHours ? $hours : $charge, $byHours ? $remainingHours : $remainingAmount) > 0) {
                    break;
                }

                $remainingHours = Money::sub($remainingHours, $hours);
                $remainingAmount = Money::sub($remainingAmount, $charge);
                $burnedHours = Money::add($burnedHours, $hours);
                $burnedAmount = Money::add($burnedAmount, $charge);
                $ids[] = (string) $sheet->id;

                ProjectRetainerLedgerEntry::create([
                    'tenant_id' => $project->tenant_id,
                    'company_id' => $project->company_id,
                    'project_id' => $project->id,
                    'billing_contract_id' => $contract->id,
                    'entry_type' => ProjectRetainerLedgerEntry::Consumption,
                    'source_type' => 'timesheet',
                    'source_id' => (string) $sheet->id,
                    'entry_date' => $sheet->work_date->toDateString(),
                    'hours' => Money::round($hours, 4),
                    'amount' => Money::round($charge, 4),
                    'balance_hours_after' => Money::round($remainingHours, 4),
                    'balance_amount_after' => Money::round($remainingAmount, 4),
                    'created_by' => $actorUserId,
                ]);
            }

            if ($ids !== []) {
                $this->raiseLowBalanceAlert($project, $contract);
                $this->audit->record($project, $actorUserId, 'retainer_consumed', 'Retainer burned by '.Money::round($burnedHours, 2).' h', [], ['timesheets' => count($ids)], $contract);
            }

            return ['consumed_hours' => Money::round($burnedHours, 2), 'consumed_amount' => Money::amount($burnedAmount), 'timesheet_ids' => $ids];
        });
    }

    /**
     * @return list<string>
     */
    public function consumedTimesheetIds(ProjectBillingContract $contract): array
    {
        return ProjectRetainerLedgerEntry::query()
            ->where('billing_contract_id', $contract->id)
            ->where('entry_type', ProjectRetainerLedgerEntry::Consumption)
            ->where('source_type', 'timesheet')
            ->pluck('source_id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function raiseLowBalanceAlert(Project $project, ProjectBillingContract $contract): void
    {
        $balance = $this->balance($contract);

        if (! $balance['is_low_balance'] || $contract->low_balance_alerted_at !== null) {
            return;
        }

        $contract->update(['low_balance_alerted_at' => now()]);

        $this->dispatcher->publishAndDispatch(new ProjectRetainerLowBalanceEvent(
            projectId: (string) $project->id,
            billingContractId: (string) $contract->id,
            remainingHours: $balance['remaining_hours'],
            remainingAmount: $balance['remaining_amount'],
            remainingPercent: $balance['remaining_percent'],
            thresholdPercent: $balance['low_balance_threshold_percent'],
            tenantId: (string) $project->tenant_id,
            companyId: (string) ($project->company_id ?? $project->tenant_id),
        ));
    }

    private function assertRetainer(ProjectBillingContract $contract): void
    {
        if (! $contract->model()->usesRetainer()) {
            throw new ConflictHttpException(__('project_billing.errors.not_a_retainer_contract'));
        }
    }
}
