<?php

declare(strict_types=1);

namespace Modules\Planning\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Planning\Models\PlanningApproval;
use Modules\Planning\Models\PlanningPlanLine;
use Modules\Planning\Models\PlanningPublication;
use Modules\Planning\Models\PlanningScenario;

/**
 * The S&OP cycle of a scenario: draft, review (submitted), approved, locked. Approving a plan that overloads a resource
 * needs an explicit, commented acceptance of the overload. An approved or locked plan is published as a numbered,
 * frozen version; only the latest publication of the company is current.
 */
final readonly class CycleService
{
    public function __construct(private BalanceService $balance, private CapacityService $capacity) {}

    public function submit(PlanningScenario $scenario, ?string $userId, ?string $comment): PlanningScenario
    {
        return $this->transition($scenario, [PlanningScenario::Draft], PlanningScenario::Review, PlanningApproval::Submit, $userId, $comment, function (PlanningScenario $locked) use ($userId): void {
            if (! PlanningPlanLine::query()->where('scenario_id', $locked->id)->exists()) {
                throw ValidationException::withMessages(['status' => [__('planning_errors.cycle.empty')]]);
            }

            $locked->submitted_by = $userId;
            $locked->submitted_at = now();
        });
    }

    public function approve(PlanningScenario $scenario, ?string $userId, ?string $comment, bool $acceptOverload): PlanningScenario
    {
        return $this->transition($scenario, [PlanningScenario::Review], PlanningScenario::Approved, PlanningApproval::Approve, $userId, $comment, function (PlanningScenario $locked) use ($userId, $comment, $acceptOverload): void {
            $check = $this->capacity->check($locked);

            if (! $check['summary']['feasible']) {
                if (! $acceptOverload) {
                    throw ValidationException::withMessages(['accept_overload' => [__('planning_errors.cycle.capacity_overloaded', ['count' => $check['summary']['overloaded_count']])]]);
                }

                if ($comment === null || trim($comment) === '') {
                    throw ValidationException::withMessages(['comment' => [__('planning_errors.cycle.overload_comment')]]);
                }
            }

            $locked->approved_by = $userId;
            $locked->approved_at = now();
        });
    }

    public function reject(PlanningScenario $scenario, ?string $userId, ?string $comment): PlanningScenario
    {
        if ($comment === null || trim($comment) === '') {
            throw ValidationException::withMessages(['comment' => [__('planning_errors.cycle.reason_required')]]);
        }

        return $this->transition($scenario, [PlanningScenario::Review, PlanningScenario::Approved], PlanningScenario::Draft, PlanningApproval::Reject, $userId, $comment, function (PlanningScenario $locked): void {
            if ($locked->published_version > 0) {
                throw ValidationException::withMessages(['status' => [__('planning_errors.scenario.published')]]);
            }

            $locked->submitted_by = null;
            $locked->submitted_at = null;
            $locked->approved_by = null;
            $locked->approved_at = null;
        });
    }

    public function lock(PlanningScenario $scenario, ?string $userId, ?string $comment): PlanningScenario
    {
        return $this->transition($scenario, [PlanningScenario::Approved], PlanningScenario::Locked, PlanningApproval::Lock, $userId, $comment, function (PlanningScenario $locked) use ($userId): void {
            $locked->locked_by = $userId;
            $locked->locked_at = now();
        });
    }

    /**
     * Publishes an approved or locked scenario: a new version number, a frozen snapshot, the previous publication stops
     * being current.
     */
    public function publish(PlanningScenario $scenario, ?string $userId, ?string $comment): PlanningPublication
    {
        return DB::transaction(function () use ($scenario, $userId, $comment): PlanningPublication {
            TransactionLock::acquire('planning_publish:'.$scenario->tenant_id);
            $fresh = PlanningScenario::query()->findOrFail($scenario->id);

            if (! in_array($fresh->status, [PlanningScenario::Approved, PlanningScenario::Locked], true)) {
                throw ValidationException::withMessages(['status' => [__('planning_errors.cycle.not_publishable')]]);
            }

            $version = (int) PlanningPublication::query()->where('tenant_id', $fresh->tenant_id)->max('version') + 1;
            $balance = $this->balance->balance($fresh, true);
            $capacity = $this->capacity->check($fresh);

            PlanningPublication::query()->where('tenant_id', $fresh->tenant_id)->where('is_current', true)->get()->each->update(['is_current' => false]);

            $publication = PlanningPublication::create([
                'tenant_id' => $fresh->tenant_id,
                'scenario_id' => $fresh->id,
                'version' => $version,
                'is_current' => true,
                'snapshot' => [
                    'scenario' => ['id' => $fresh->id, 'name' => $fresh->name, 'scenario_type' => $fresh->scenario_type, 'status' => $fresh->status],
                    'totals' => $balance['totals'],
                    'items' => array_map(static fn (array $item): array => [
                        'item_code' => $item['item_code'],
                        'total_demand' => $item['total_demand'],
                        'total_supply' => $item['total_supply'],
                        'total_gap' => $item['total_gap'],
                        'max_shortage' => $item['max_shortage'],
                    ], $balance['items']),
                    'capacity' => $capacity['summary'],
                ],
                'published_by' => $userId,
                'published_at' => now(),
            ]);

            $fresh->published_version = $version;
            $fresh->published_at = now();
            $fresh->published_by = $userId;
            $fresh->save();

            $this->log($fresh, PlanningApproval::Publish, $fresh->status, $fresh->status, $userId, $comment);

            return $publication->refresh();
        });
    }

    /**
     * @param  list<string>  $from
     * @param  callable(PlanningScenario): void  $apply
     */
    private function transition(PlanningScenario $scenario, array $from, string $to, string $action, ?string $userId, ?string $comment, callable $apply): PlanningScenario
    {
        return DB::transaction(function () use ($scenario, $from, $to, $action, $userId, $comment, $apply): PlanningScenario {
            TransactionLock::acquire('planning_scenario:'.$scenario->id);
            $locked = PlanningScenario::query()->findOrFail($scenario->id);

            if (! in_array($locked->status, $from, true)) {
                throw ValidationException::withMessages(['status' => [__('planning_errors.cycle.invalid_transition', ['status' => $locked->status, 'action' => $action])]]);
            }

            $apply($locked);
            $previous = $locked->status;
            $locked->status = $to;
            $locked->save();
            $this->log($locked, $action, $previous, $to, $userId, $comment);

            return $locked->refresh();
        });
    }

    private function log(PlanningScenario $scenario, string $action, string $from, string $to, ?string $userId, ?string $comment): void
    {
        PlanningApproval::create([
            'tenant_id' => $scenario->tenant_id,
            'scenario_id' => $scenario->id,
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'comment' => $comment,
        ]);
    }
}
