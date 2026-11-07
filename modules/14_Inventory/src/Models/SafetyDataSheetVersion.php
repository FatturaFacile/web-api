<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One revision of a sheet. Revisions are never changed: a correction is a new version.
 */
final class SafetyDataSheetVersion extends BaseEnterpriseModel
{
    protected $table = 'safety_data_sheet_versions';

    /** @var list<string> */
    protected $hidden = ['disk', 'path'];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'revision_date' => 'date',
            'hazard_classes' => 'array',
            'size' => 'integer',
        ];
    }

    public function sheet(): BelongsTo
    {
        return $this->belongsTo(SafetyDataSheet::class, 'safety_data_sheet_id');
    }
}
