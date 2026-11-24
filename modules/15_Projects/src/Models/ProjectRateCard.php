<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\User;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An hourly cost rate and billing rate for a person or a role, optionally limited to one project and to a validity
 * period. Timesheets take their rates from the most specific card valid on the work date (see ProjectRateResolver).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string|null $project_id
 * @property string|null $user_id
 * @property string|null $role
 * @property string $cost_rate
 * @property string $bill_rate
 * @property CarbonInterface|null $valid_from
 * @property CarbonInterface|null $valid_to
 */
final class ProjectRateCard extends BaseEnterpriseModel
{
    protected $table = 'project_rate_cards';

    protected function casts(): array
    {
        return [
            'cost_rate' => 'decimal:2',
            'bill_rate' => 'decimal:2',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
