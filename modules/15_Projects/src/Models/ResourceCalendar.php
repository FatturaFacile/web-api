<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ResourceCalendar extends BaseEnterpriseModel
{
    protected $table = 'resource_calendars';

    protected function casts(): array
    {
        return [
            'daily_available_hours' => 'decimal:2',
            'exception_date' => 'date',
            'is_available' => 'boolean',
        ];
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(ProjectResource::class, 'resource_id');
    }
}
