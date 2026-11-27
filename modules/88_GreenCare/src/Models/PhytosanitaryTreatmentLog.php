<?php

declare(strict_types=1);

namespace Modules\GreenCare\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;
use Modules\GreenCare\Domain\Enums\DosageUnit;

/**
 * An entry of the phytosanitary diary (Quaderno di Campagna / Registro dei trattamenti). The diary is audit evidence: an entry is
 * never changed or deleted after it is written.
 *
 * @property string $site_id
 * @property string|null $route_stop_id
 * @property Carbon $treatment_date
 * @property string $jurisdiction
 * @property string $target_pest_or_weed
 * @property string $treated_crop_or_surface
 * @property string $treated_area_sqm
 * @property string $commercial_product_name
 * @property string $registration_number
 * @property string $active_ingredient
 * @property string $dosage_used
 * @property DosageUnit $dosage_unit
 * @property string|null $water_volume_liters
 * @property string|null $warehouse_product_id
 * @property string|null $warehouse_id
 * @property string $quantity_deducted
 * @property string $operator_id
 * @property string $operator_license_id
 * @property int $re_entry_interval_hours
 * @property array<string, mixed>|null $weather_conditions_at_application
 * @property string|null $notes
 */
final class PhytosanitaryTreatmentLog extends BaseEnterpriseModel
{
    protected $table = 'phytosanitary_treatment_logs';

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('The phytosanitary diary is immutable.');
        });
        static::deleting(static function (): never {
            throw new LogicException('The phytosanitary diary is immutable.');
        });
    }

    protected function casts(): array
    {
        return [
            'treatment_date' => 'date',
            'treated_area_sqm' => 'decimal:2',
            'dosage_used' => 'decimal:4',
            'dosage_unit' => DosageUnit::class,
            'water_volume_liters' => 'decimal:2',
            'quantity_deducted' => 'decimal:4',
            're_entry_interval_hours' => 'integer',
            'weather_conditions_at_application' => 'array',
        ];
    }

    /**
     * @return BelongsTo<GreenSpaceSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(GreenSpaceSite::class, 'site_id');
    }

    /**
     * @return BelongsTo<CrewTeamMember, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(CrewTeamMember::class, 'operator_id');
    }

    /**
     * @return BelongsTo<OperatorPhytosanitaryLicense, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(OperatorPhytosanitaryLicense::class, 'operator_license_id');
    }
}
