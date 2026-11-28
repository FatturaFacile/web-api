<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One inspection of a PPE item: the daily visual check, the annual EN 365 inspection by a competent person (with license number and
 * signature) or the check after an incident.
 *
 * @property string $ppe_asset_id
 * @property string $inspection_type
 * @property string $outcome
 * @property Carbon $inspected_on
 * @property string|null $inspector_name
 * @property string|null $inspector_license_number
 * @property string|null $signature
 * @property string|null $notes
 * @property array<int, array{key: string, ok: bool}>|null $checklist
 * @property Carbon|null $next_due_date
 */
final class PpeInspectionLog extends BaseEnterpriseModel
{
    protected $table = 'adv_ppe_inspection_logs';

    protected function casts(): array
    {
        return [
            'inspected_on' => 'date',
            'next_due_date' => 'date',
            'checklist' => 'array',
        ];
    }

    /**
     * @return BelongsTo<PpeAsset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(PpeAsset::class, 'ppe_asset_id');
    }
}
