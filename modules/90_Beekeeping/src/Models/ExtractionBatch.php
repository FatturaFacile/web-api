<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\Beekeeping\Domain\ValueObjects\HoneyQuality;

/**
 * An extraction batch (lotto di smielatura): the HACCP lot every jar traces back to.
 *
 * @property string $lot_code
 * @property Carbon $extracted_on
 * @property string $botanical_origin
 * @property string $extracted_kg
 * @property int $hives_harvested
 * @property string|null $tank_code
 * @property Carbon|null $decanted_on
 * @property string|null $moisture_pct
 * @property string|null $hmf_mg_kg
 * @property string|null $organoleptic_notes
 * @property string|null $process_notes
 */
final class ExtractionBatch extends BaseEnterpriseModel
{
    protected $table = 'bee_extraction_batches';

    protected function casts(): array
    {
        return [
            'extracted_on' => 'date',
            'extracted_kg' => 'decimal:2',
            'hives_harvested' => 'integer',
            'decanted_on' => 'date',
            'moisture_pct' => 'decimal:1',
            'hmf_mg_kg' => 'decimal:1',
        ];
    }

    public function quality(): HoneyQuality
    {
        return HoneyQuality::fromConfig($this->moisture_pct === null ? null : (float) $this->moisture_pct, $this->hmf_mg_kg === null ? null : (float) $this->hmf_mg_kg);
    }

    public function yieldPerHiveKg(): ?float
    {
        return $this->hives_harvested > 0 ? round((float) $this->extracted_kg / $this->hives_harvested, 2) : null;
    }

    /**
     * @return HasMany<SuperIntake, $this>
     */
    public function intakes(): HasMany
    {
        return $this->hasMany(SuperIntake::class, 'extraction_batch_id');
    }

    /**
     * @return HasMany<JarringRun, $this>
     */
    public function jarringRuns(): HasMany
    {
        return $this->hasMany(JarringRun::class, 'extraction_batch_id');
    }
}
