<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\CertificationStatus;
use Modules\IndustrialLaundry\Domain\Enums\RabcRiskZone;

/**
 * A wash batch with its UNI EN 14065 RABC certificate (WashBatchCert): the program, the targets, what the line measured and
 * the hygiene verdict.
 *
 * @property string $batch_code
 * @property string $disinfection_thermal_chem_program_code
 * @property RabcRiskZone $rabc_risk_zone
 * @property CertificationStatus $rabc_control_status
 * @property string $target_temperature_c
 * @property int $target_hold_minutes
 * @property string|null $target_chemical_dosing_ml_per_kg
 * @property string|null $measured_temperature_c
 * @property int|null $measured_hold_minutes
 * @property string|null $measured_chemical_dosing_ml_per_kg
 * @property string|null $microbiological_cfu_per_25cm2
 * @property string $load_net_weight_kg
 * @property int $garments_count
 * @property string|null $operator_name
 * @property string|null $verifier_name
 * @property string|null $verified_by_user_id
 * @property list<array<string, mixed>>|null $violations
 * @property Carbon|null $washed_at
 * @property Carbon|null $certified_at
 */
final class WashBatch extends BaseEnterpriseModel
{
    protected $table = 'ilr_wash_batches';

    protected function casts(): array
    {
        return [
            'rabc_risk_zone' => RabcRiskZone::class,
            'rabc_control_status' => CertificationStatus::class,
            'target_temperature_c' => 'decimal:2',
            'target_hold_minutes' => 'integer',
            'target_chemical_dosing_ml_per_kg' => 'decimal:3',
            'measured_temperature_c' => 'decimal:2',
            'measured_hold_minutes' => 'integer',
            'measured_chemical_dosing_ml_per_kg' => 'decimal:3',
            'microbiological_cfu_per_25cm2' => 'decimal:2',
            'load_net_weight_kg' => 'decimal:3',
            'garments_count' => 'integer',
            'violations' => 'array',
            'washed_at' => 'datetime',
            'certified_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Garment, $this>
     */
    public function garments(): BelongsToMany
    {
        return $this->belongsToMany(Garment::class, 'ilr_wash_batch_garments', 'wash_batch_id', 'garment_id')->withPivot(['id', 'tenant_id'])->withTimestamps();
    }

    /**
     * @return BelongsToMany<ScanSession, $this>
     */
    public function sessions(): BelongsToMany
    {
        return $this->belongsToMany(ScanSession::class, 'ilr_wash_batch_sessions', 'wash_batch_id', 'scan_session_id')->withPivot(['id', 'tenant_id'])->withTimestamps();
    }
}
