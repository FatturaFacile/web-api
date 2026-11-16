<?php

declare(strict_types=1);

namespace Modules\Wms\Services;

use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Wms\Models\WmsTask;
use Modules\Wms\Models\WmsWave;
use Modules\Wms\Models\WmsWaveRule;

/**
 * Wave planning. A wave rule picks the open, unassigned pick tasks of its warehouse that fit its criteria (minimum
 * priority, due window, reference prefix), most urgent first and up to its maximum size, and groups them in a planned
 * wave. Releasing the wave offers its tasks to the operators; it completes when every task is closed.
 */
final class WaveService
{
    /** Task statuses that end a task. */
    private const array Closed = [WmsTask::Completed, WmsTask::ShortCompleted, WmsTask::Skipped, WmsTask::Cancelled];

    public function __construct(private readonly WmsNumbering $numbering) {}

    public function plan(string $tenantId, string $ruleId): WmsWave
    {
        return DB::transaction(function () use ($tenantId, $ruleId): WmsWave {
            TransactionLock::acquire("wms:waveplan:{$tenantId}");

            $rule = WmsWaveRule::query()->where('tenant_id', $tenantId)->findOrFail($ruleId);

            if (! $rule->is_active) {
                throw ValidationException::withMessages(['wave_rule_id' => [__('wms_errors.wave.rule_inactive')]]);
            }

            $tasks = WmsTask::query()
                ->where('tenant_id', $tenantId)
                ->where('warehouse_id', $rule->warehouse_id)
                ->where('task_type', 'pick')
                ->where('status', WmsTask::Open)
                ->whereNull('assigned_to')
                ->whereNull('wave_id')
                ->when($rule->min_priority !== null, static fn ($query) => $query->where('priority', '>=', $rule->min_priority))
                ->when($rule->due_within_hours !== null, static fn ($query) => $query->where(static fn ($inner) => $inner->whereNull('due_at')->orWhere('due_at', '<=', now()->addHours($rule->due_within_hours))))
                ->when($rule->reference_prefix !== null, static fn ($query) => $query->where('reference', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $rule->reference_prefix).'%'))
                ->orderByDesc('priority')
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')
                ->orderBy('created_at')
                ->limit($rule->max_tasks)
                ->get();

            if ($tasks->count() < $rule->min_tasks) {
                throw ValidationException::withMessages(['wave_rule_id' => [__('wms_errors.wave.not_enough_tasks', ['found' => $tasks->count(), 'minimum' => $rule->min_tasks])]]);
            }

            $wave = WmsWave::create([
                'tenant_id' => $tenantId,
                'warehouse_id' => $rule->warehouse_id,
                'wave_rule_id' => $rule->id,
                'number' => $this->numbering->next(WmsWave::class, 'number', 'WV', $tenantId),
                'task_count' => $tasks->count(),
            ]);
            WmsTask::query()->whereIn('id', $tasks->modelKeys())->update(['wave_id' => $wave->id]);

            return $this->present($wave);
        });
    }

    public function release(WmsWave $wave): WmsWave
    {
        return DB::transaction(function () use ($wave): WmsWave {
            $locked = $this->lock($wave);
            $this->assertStatus($locked, [WmsWave::Planned]);
            $locked->update(['status' => WmsWave::Released, 'released_at' => now()]);

            return $this->present($locked);
        });
    }

    public function complete(WmsWave $wave): WmsWave
    {
        return DB::transaction(function () use ($wave): WmsWave {
            $locked = $this->lock($wave);
            $this->assertStatus($locked, [WmsWave::Released]);
            $open = WmsTask::query()->where('wave_id', $locked->id)->whereNotIn('status', self::Closed)->count();

            if ($open > 0) {
                throw ValidationException::withMessages(['status' => [__('wms_errors.wave.tasks_open', ['count' => $open])]]);
            }

            $locked->update(['status' => WmsWave::Completed, 'completed_at' => now()]);

            return $this->present($locked);
        });
    }

    public function cancel(WmsWave $wave): WmsWave
    {
        return DB::transaction(function () use ($wave): WmsWave {
            $locked = $this->lock($wave);
            $this->assertStatus($locked, [WmsWave::Planned, WmsWave::Released]);
            $started = WmsTask::query()->where('wave_id', $locked->id)->whereIn('status', [WmsTask::InProgress, WmsTask::Completed, WmsTask::ShortCompleted])->exists();

            if ($started) {
                throw ValidationException::withMessages(['status' => [__('wms_errors.wave.work_started')]]);
            }

            WmsTask::query()->where('wave_id', $locked->id)->update(['wave_id' => null]);
            $locked->update(['status' => WmsWave::Cancelled]);

            return $this->present($locked);
        });
    }

    private function present(WmsWave $wave): WmsWave
    {
        return $wave->refresh()->load('warehouse:id,code,name');
    }

    private function lock(WmsWave $wave): WmsWave
    {
        return WmsWave::query()->lockForUpdate()->findOrFail($wave->id);
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertStatus(WmsWave $wave, array $allowed): void
    {
        if (! in_array($wave->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [__('wms_errors.wave.invalid_transition', ['status' => $wave->status])]]);
        }
    }
}
