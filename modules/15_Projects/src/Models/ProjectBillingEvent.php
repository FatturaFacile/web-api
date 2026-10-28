<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectBillingEvent extends BaseEnterpriseModel
{
    protected $table = 'project_billing_events';

    protected function casts(): array
    {
        return [
            'billing_amount' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
