<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Manufacturing\Enums\MachineMode;
use Modules\Manufacturing\Enums\PhaseStatus;

/**
 * A phase (Italian: fase di lavorazione) on the MES operator board: one production order operation taken over by an
 * operator, with its lifecycle state. `version` is the optimistic concurrency token, raised by every change.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $production_order_id
 * @property string $production_order_operation_id
 * @property string $work_center_id
 * @property string|null $machine_id
 * @property string|null $operator_id
 * @property PhaseStatus $status
 * @property PhaseStatus|null $resume_to
 * @property MachineMode $machine_mode
 * @property int $version
 * @property Carbon $taken_over_at
 * @property Carbon|null $closed_at
 * @property string|null $confirmation_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgMesPhase extends BaseEnterpriseModel
{
    protected $table = 'mfg_mes_phases';

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => PhaseStatus::class,
            'resume_to' => PhaseStatus::class,
            'machine_mode' => MachineMode::class,
            'version' => 'integer',
            'taken_over_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MfgProductionOrderOperation, $this>
     */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(MfgProductionOrderOperation::class, 'production_order_operation_id');
    }

    /**
     * @return HasMany<MfgMesTimeLog, $this>
     */
    public function timeLogs(): HasMany
    {
        return $this->hasMany(MfgMesTimeLog::class, 'phase_id')->orderBy('started_at');
    }

    /**
     * @return HasMany<MfgMesProductionDeclaration, $this>
     */
    public function declarations(): HasMany
    {
        return $this->hasMany(MfgMesProductionDeclaration::class, 'phase_id')->orderBy('declared_at');
    }

    /**
     * @return HasMany<MfgMesMaterialConsumption, $this>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(MfgMesMaterialConsumption::class, 'phase_id')->orderBy('consumed_at');
    }
}
