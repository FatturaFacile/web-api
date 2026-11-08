<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SafetyDataSheetDelivery extends BaseEnterpriseModel
{
    protected $table = 'safety_data_sheet_deliveries';

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SafetyDataSheetVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(SafetyDataSheetVersion::class, 'safety_data_sheet_version_id');
    }
}
