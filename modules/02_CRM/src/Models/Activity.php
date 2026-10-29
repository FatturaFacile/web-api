<?php

declare(strict_types=1);

namespace Modules\CRM\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Activity extends BaseEnterpriseModel
{
    protected $table = 'crm_activities';

    protected function casts(): array
    {
        return [
            'due_date' => 'datetime',
            'completed_at' => 'datetime',
            'duration_minutes' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function related(): MorphTo
    {
        return $this->morphTo(null, 'related_type', 'related_id');
    }
}
