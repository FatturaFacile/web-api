<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\DairyCheeseManufacturing\Exceptions\DairyCheeseException;
use Modules\DairyCheeseManufacturing\Models\AgingCareLog;
use Modules\DairyCheeseManufacturing\Models\AgingRack;
use Modules\DairyCheeseManufacturing\Models\CheeseAgingWheel;
use Modules\DairyCheeseManufacturing\Models\CheeseVatBatch;

/**
 * Care scheduler of the aging cellar: scheduled operations on a wheel, a shelf, a whole rack or a vat batch, their execution
 * (one by one or for the whole scope at once) and the recurrence of the periodic ones.
 */
final class CareService
{
    /**
     * @return list<string>
     */
    public function wheelIds(string $scope, string $targetId): array
    {
        $active = ['brine', 'aging'];

        return match ($scope) {
            'wheel' => CheeseAgingWheel::query()->whereKey($targetId)->whereIn('status', $active)->pluck('id')->all(),
            'shelf' => CheeseAgingWheel::query()->where('rack_id', $targetId)->whereIn('status', $active)->pluck('id')->all(),
            'rack' => CheeseAgingWheel::query()->whereIn('rack_id', $this->rackGroupIds($targetId))->whereIn('status', $active)->pluck('id')->all(),
            'batch' => CheeseAgingWheel::query()->where('vat_batch_id', $targetId)->whereIn('status', $active)->pluck('id')->all(),
            default => [],
        };
    }

    /**
     * @return list<string>
     */
    public function rackGroupIds(string $rackRowId): array
    {
        $row = AgingRack::query()->findOrFail($rackRowId);

        return AgingRack::query()->where('room', $row->room)->where('rack', $row->rack)->pluck('id')->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function schedule(array $data, ?string $userId = null): AgingCareLog
    {
        $scope = (string) $data['scope'];
        $this->assertTarget($scope, (string) $data['target_id']);

        return AgingCareLog::query()->create([
            'care_type' => (string) $data['care_type'], 'status' => 'scheduled', 'scope' => $scope, 'target_id' => (string) $data['target_id'],
            'scheduled_for' => (string) $data['scheduled_for'], 'notes' => $data['notes'] ?? null, 'created_by' => $userId,
        ]);
    }

    public function execute(AgingCareLog $log, ?string $performedBy, ?string $notes): AgingCareLog
    {
        if ($log->status !== 'scheduled') {
            throw new DairyCheeseException('The operation is already closed.', 'care_already_closed');
        }

        return DB::transaction(function () use ($log, $performedBy, $notes): AgingCareLog {
            $affected = count($this->wheelIds((string) $log->scope, (string) $log->target_id));

            $log->forceFill([
                'status' => 'done', 'executed_at' => now(), 'wheels_affected' => $affected,
                'performed_by' => $performedBy ?? $log->performed_by, 'notes' => $notes ?? $log->notes,
            ])->save();

            $this->afterExecution($log);

            return $log;
        });
    }

    /**
     * Marks a whole shelf, rack or batch as turned / brushed / washed: the matching scheduled operations are closed and, when
     * none was scheduled, one executed operation is recorded.
     */
    public function executeBatch(string $careType, string $scope, string $targetId, ?string $performedBy, ?string $notes): AgingCareLog
    {
        if ($scope === 'wheel') {
            throw new DairyCheeseException('Use the single operation for one wheel.', 'care_scope_invalid');
        }

        $this->assertTarget($scope, $targetId);

        return DB::transaction(function () use ($careType, $scope, $targetId, $performedBy, $notes): AgingCareLog {
            $pending = AgingCareLog::query()->where('care_type', $careType)->where('scope', $scope)->where('target_id', $targetId)->where('status', 'scheduled')->orderBy('scheduled_for')->get();
            $affected = count($this->wheelIds($scope, $targetId));
            $last = null;

            foreach ($pending as $log) {
                $log->forceFill(['status' => 'done', 'executed_at' => now(), 'wheels_affected' => $affected, 'performed_by' => $performedBy, 'notes' => $notes ?? $log->notes])->save();
                $this->afterExecution($log);
                $last = $log;
            }

            if ($last !== null) {
                return $last;
            }

            $log = AgingCareLog::query()->create([
                'care_type' => $careType, 'status' => 'done', 'scope' => $scope, 'target_id' => $targetId, 'scheduled_for' => now()->toDateString(),
                'executed_at' => now(), 'wheels_affected' => $affected, 'performed_by' => $performedBy, 'notes' => $notes,
            ]);
            $this->afterExecution($log);

            return $log;
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AgingCareLog>
     */
    public function query(array $filters): Builder
    {
        $query = AgingCareLog::query()->orderBy('scheduled_for')->orderBy('created_at');

        foreach (['status', 'care_type'] as $field) {
            if (! empty($filters[$field])) {
                $query->where($field, (string) $filters[$field]);
            }
        }

        if (! empty($filters['overdue'])) {
            $query->where('status', 'scheduled')->whereDate('scheduled_for', '<', CarbonImmutable::today()->toDateString());
        }

        if (! empty($filters['from'])) {
            $query->whereDate('scheduled_for', '>=', (string) $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->whereDate('scheduled_for', '<=', (string) $filters['to']);
        }

        return $query;
    }

    public function isOverdue(AgingCareLog $log): bool
    {
        return $log->status === 'scheduled' && $log->scheduled_for->lt(CarbonImmutable::today());
    }

    /**
     * Wheel ids that have at least one overdue operation on them (directly or through their shelf, rack or batch).
     *
     * @return array<string, true>
     */
    public function overdueWheelIds(): array
    {
        $ids = [];

        $overdue = AgingCareLog::query()->where('status', 'scheduled')->whereDate('scheduled_for', '<', CarbonImmutable::today()->toDateString())->get();

        foreach ($overdue as $log) {
            foreach ($this->wheelIds((string) $log->scope, (string) $log->target_id) as $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    private function afterExecution(AgingCareLog $log): void
    {
        /** @var array{repeat_every_days?: int}|null $rule */
        $rule = config('dairy_cheese.care_schedule.'.$log->care_type);
        $days = (int) ($rule['repeat_every_days'] ?? 0);

        if ($days > 0 && $log->scope !== 'wheel' && $this->wheelIds((string) $log->scope, (string) $log->target_id) !== []) {
            AgingCareLog::query()->create([
                'care_type' => $log->care_type, 'status' => 'scheduled', 'scope' => $log->scope, 'target_id' => $log->target_id,
                'scheduled_for' => CarbonImmutable::parse($log->executed_at)->addDays($days)->toDateString(),
            ]);
        }

        if ($log->care_type === 'brining' && $log->scope === 'batch') {
            CheeseAgingWheel::query()->where('vat_batch_id', $log->target_id)->where('status', 'brine')->where('rack_id', '!=', null)->update(['status' => 'aging']);
        }
    }

    private function assertTarget(string $scope, string $targetId): void
    {
        $exists = match ($scope) {
            'wheel' => CheeseAgingWheel::query()->whereKey($targetId)->exists(),
            'shelf', 'rack' => AgingRack::query()->whereKey($targetId)->exists(),
            'batch' => CheeseVatBatch::query()->whereKey($targetId)->exists(),
            default => false,
        };

        if (! $exists) {
            throw new DairyCheeseException('The target of the operation does not exist.', 'care_target_not_found', 404);
        }
    }
}
