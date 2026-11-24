<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use App\Exceptions\BusinessRuleException;
use App\Support\Database\TransactionLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Wms\Contracts\WmsStockGateway;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Models\WmsWave;
use Modules\Wms\Support\WarehouseBins;

/**
 * The RF task queue. A task is open (in the queue), assigned (reserved for an operator), in progress (being done) and
 * ends completed, short completed (done with less than planned), skipped (parked after repeated skips) or cancelled.
 * Skipping sends a task back to the queue with a lower priority.
 */
final class TaskService extends WmsCrudService
{
    /** Skips after which a task is parked for a supervisor instead of going back to the queue. */
    public const int MaxSkips = 3;

    /** Priority points lost by a task every time it is skipped. */
    private const int SkipPenalty = 10;

    /** Fields every kind of task needs. */
    private const array Required = [
        'putaway' => ['destination_bin_id', 'stock_item_id'],
        'pick' => ['source_bin_id', 'stock_item_id'],
        'replenish' => ['source_bin_id', 'destination_bin_id', 'stock_item_id'],
        'count' => ['source_bin_id'],
        'move' => ['source_bin_id', 'destination_bin_id', 'stock_item_id'],
    ];

    /** @var list<string> */
    private const array With = ['warehouse:id,code,name', 'stockItem:id,sku,name', 'sourceBin:id,code', 'destinationBin:id,code'];

    public function __construct(private readonly WmsStockGateway $stock) {}

    protected function modelClass(): string
    {
        return WmsTask::class;
    }

    protected function prepare(string $tenantId, array $data, ?Model $existing): array
    {
        /** @var WmsTask|null $existing */
        if ($existing !== null && ! in_array($existing->status, [WmsTask::Open, WmsTask::Assigned], true)) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.task.not_editable', ['status' => $existing->status])]]);
        }

        $merged = [...($existing?->only(['warehouse_id', 'task_type', 'source_bin_id', 'destination_bin_id', 'stock_item_id']) ?? []), ...$data];
        $warehouseId = (string) $merged['warehouse_id'];

        foreach (self::Required[$merged['task_type']] as $field) {
            if (($merged[$field] ?? null) === null) {
                throw ValidationException::withMessages([$field => [__('wms_errors.task.field_required', ['type' => $merged['task_type']])]]);
            }
        }

        foreach (['source_bin_id', 'destination_bin_id'] as $field) {
            if (($merged[$field] ?? null) === null) {
                continue;
            }

            $bin = WarehouseBins::find($tenantId, (string) $merged[$field]);

            if ($bin === null || (string) $bin->warehouse_id !== $warehouseId) {
                throw ValidationException::withMessages([$field => [__('wms_errors.task.bin_other_warehouse')]]);
            }

            if ($field === 'destination_bin_id' && (bool) $bin->is_locked) {
                throw ValidationException::withMessages([$field => [__('wms_errors.task.bin_locked', ['bin' => $bin->code])]]);
            }
        }

        if (($merged['source_bin_id'] ?? null) !== null && $merged['source_bin_id'] === ($merged['destination_bin_id'] ?? null)) {
            throw ValidationException::withMessages(['destination_bin_id' => [__('wms_errors.task.same_bin')]]);
        }

        if (array_key_exists('assigned_to', $data)) {
            if ($data['assigned_to'] !== null) {
                $this->assertMember($tenantId, (string) $data['assigned_to']);
            }

            $data['status'] = $data['assigned_to'] !== null ? WmsTask::Assigned : WmsTask::Open;
        }

        return $data;
    }

    protected function assertDeletable(Model $record): void
    {
        /** @var WmsTask $record */
        if (! in_array($record->status, [WmsTask::Open, WmsTask::Cancelled], true)) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.task.not_deletable', ['status' => $record->status])]]);
        }
    }

    public function present(WmsTask $task): WmsTask
    {
        return $task->refresh()->load(self::With);
    }

    public function assign(WmsTask $task, string $userId): WmsTask
    {
        return DB::transaction(function () use ($task, $userId): WmsTask {
            $locked = $this->lock($task);
            $this->assertStatus($locked, [WmsTask::Open, WmsTask::Assigned]);
            $this->assertMember((string) $locked->tenant_id, $userId);
            $locked->update(['assigned_to' => $userId, 'status' => WmsTask::Assigned]);

            return $this->present($locked);
        });
    }

    public function start(WmsTask $task, string $userId): WmsTask
    {
        return DB::transaction(function () use ($task, $userId): WmsTask {
            $locked = $this->lock($task);
            $this->assertStatus($locked, [WmsTask::Open, WmsTask::Assigned]);
            $this->assertStartable($locked, $userId);
            $locked->update(['status' => WmsTask::InProgress, 'assigned_to' => $userId, 'started_at' => now()]);

            return $this->present($locked);
        });
    }

    public function confirm(WmsTask $task, ?string $quantity, ?string $userId = null): WmsTask
    {
        return DB::transaction(function () use ($task, $quantity, $userId): WmsTask {
            $locked = $this->lock($task);
            $this->assertStatus($locked, [WmsTask::InProgress]);
            $planned = (string) $locked->planned_quantity;
            $quantity ??= $planned;

            if (bccomp($quantity, $planned, 4) > 0) {
                throw ValidationException::withMessages(['quantity' => [__('wms_errors.task.over_planned', ['planned' => $planned])]]);
            }

            if (bccomp($quantity, $planned, 4) < 0) {
                throw ValidationException::withMessages(['quantity' => [__('wms_errors.task.use_short', ['planned' => $planned])]]);
            }

            $this->postStock($locked, $quantity, $userId);
            $locked->update(['status' => WmsTask::Completed, 'confirmed_quantity' => $quantity, 'completed_at' => now()]);

            return $this->present($locked);
        });
    }

    public function short(WmsTask $task, string $quantity, string $reason, ?string $userId = null): WmsTask
    {
        return DB::transaction(function () use ($task, $quantity, $reason, $userId): WmsTask {
            $locked = $this->lock($task);
            $this->assertStatus($locked, [WmsTask::InProgress]);

            if (bccomp($quantity, (string) $locked->planned_quantity, 4) >= 0) {
                throw ValidationException::withMessages(['quantity' => [__('wms_errors.task.short_below_planned', ['planned' => (string) $locked->planned_quantity])]]);
            }

            $this->postStock($locked, $quantity, $userId);
            $locked->update(['status' => WmsTask::ShortCompleted, 'confirmed_quantity' => $quantity, 'short_reason' => $reason, 'completed_at' => now()]);

            return $this->present($locked);
        });
    }

    public function skip(WmsTask $task, string $reason): WmsTask
    {
        return DB::transaction(function () use ($task, $reason): WmsTask {
            $locked = $this->lock($task);
            $this->assertStatus($locked, [WmsTask::Open, WmsTask::Assigned, WmsTask::InProgress]);
            $skips = $locked->skip_count + 1;
            $parked = $skips >= self::MaxSkips;

            $locked->update([
                'status' => $parked ? WmsTask::Skipped : WmsTask::Open,
                'skip_count' => $skips,
                'skip_reason' => $reason,
                'priority' => max(0, $locked->priority - self::SkipPenalty),
                'assigned_to' => null,
                'started_at' => null,
                'completed_at' => $parked ? now() : null,
            ]);

            return $this->present($locked);
        });
    }

    public function cancel(WmsTask $task): WmsTask
    {
        return DB::transaction(function () use ($task): WmsTask {
            $locked = $this->lock($task);
            $this->assertStatus($locked, [WmsTask::Open, WmsTask::Assigned, WmsTask::InProgress]);
            $locked->update(['status' => WmsTask::Cancelled, 'completed_at' => now()]);

            return $this->present($locked);
        });
    }

    /**
     * Gives the operator the most urgent task and starts it; null when the queue holds nothing for the operator.
     */
    public function claimNext(string $tenantId, string $userId, ?string $warehouseId): ?WmsTask
    {
        return DB::transaction(function () use ($tenantId, $userId, $warehouseId): ?WmsTask {
            TransactionLock::acquire("wms:tasks:{$tenantId}");

            $base = WmsTask::query()
                ->where('tenant_id', $tenantId)
                ->when($warehouseId !== null, static fn ($query) => $query->where('warehouse_id', $warehouseId));

            $current = (clone $base)->where('status', WmsTask::InProgress)->where('assigned_to', $userId)->orderBy('started_at')->first();

            if ($current !== null) {
                return $this->present($current);
            }

            $plannedWaves = WmsWave::query()->where('tenant_id', $tenantId)->where('status', WmsWave::Planned)->select('id');
            $next = (clone $base)
                ->whereIn('status', [WmsTask::Open, WmsTask::Assigned])
                ->where(static fn ($query) => $query->whereNull('assigned_to')->orWhere('assigned_to', $userId))
                ->where(static fn ($query) => $query->whereNull('wave_id')->orWhereNotIn('wave_id', $plannedWaves))
                ->orderByDesc('priority')
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')
                ->orderBy('created_at')
                ->first();

            if ($next === null) {
                return null;
            }

            $next->update(['status' => WmsTask::InProgress, 'assigned_to' => $userId, 'started_at' => now()]);

            return $this->present($next);
        });
    }

    /**
     * Posts the stock movement of a confirmed task into the inventory, once: a task that already holds its movement
     * reference is left alone, so a retry never posts twice. A putaway without a source bin moves the goods out of the
     * receiving bin of the warehouse. Picks without a destination issue nothing here (the outbound document issues the
     * stock) and counts never move stock (the count adjusts it). Runs inside the confirming transaction: a rejected
     * movement leaves the task in progress.
     */
    private function postStock(WmsTask $task, string $quantity, ?string $userId): void
    {
        if ($task->stock_movement_id !== null || bccomp($quantity, '0', 4) <= 0 || $task->stock_item_id === null) {
            return;
        }

        $destinationBinId = $task->destination_bin_id;
        $sourceBinId = $task->source_bin_id;

        if ($task->task_type === 'putaway' && $sourceBinId === null && $destinationBinId !== null) {
            $sourceBinId = $this->stock->receivingBinId((string) $task->warehouse_id);
        }

        if ($sourceBinId === null || $destinationBinId === null || $sourceBinId === $destinationBinId || ! in_array($task->task_type, ['putaway', 'pick', 'replenish', 'move'], true)) {
            return;
        }

        try {
            $movementId = $this->stock->transfer((string) $task->id, (string) $task->stock_item_id, (string) $sourceBinId, (string) $destinationBinId, $quantity, $userId);
        } catch (BusinessRuleException $rejection) {
            throw ValidationException::withMessages(['quantity' => [__('wms_errors.task.stock_rejected', ['reason' => $rejection->getMessage()])]]);
        }

        $task->update(['stock_posting_key' => 'wms_task:'.$task->id, 'stock_movement_id' => $movementId, 'stock_posted_at' => now()]);
    }

    private function lock(WmsTask $task): WmsTask
    {
        return WmsTask::query()->lockForUpdate()->findOrFail($task->id);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(WmsTask $task, array $allowed): void
    {
        if (! in_array($task->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.task.invalid_transition', ['status' => $task->status])]]);
        }
    }

    private function assertStartable(WmsTask $task, string $userId): void
    {
        if ($task->assigned_to !== null && $task->assigned_to !== $userId) {
            throw ValidationException::withMessages(['assigned_to' => [__('wms_errors.task.assigned_to_other')]]);
        }

        if ($task->wave_id !== null && WmsWave::query()->whereKey($task->wave_id)->where('status', WmsWave::Planned)->exists()) {
            throw ValidationException::withMessages(['wave_id' => [__('wms_errors.task.wave_not_released')]]);
        }
    }

    private function assertMember(string $tenantId, string $userId): void
    {
        $member = DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $userId)->whereNull('suspended_at')->exists();

        if (! $member) {
            throw ValidationException::withMessages(['assigned_to' => [__('wms_errors.task.not_a_member')]]);
        }
    }
}
