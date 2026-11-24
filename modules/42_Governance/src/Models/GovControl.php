<?php

declare(strict_types=1);

namespace Modules\Governance\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A control of the library. It runs with a frequency and is tested periodically: every test sets the last result and
 * schedules the next test.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $title
 * @property string|null $description
 * @property string $control_type
 * @property string $frequency
 * @property string|null $framework_reference
 * @property string|null $owner_id
 * @property string $status
 * @property Carbon|null $last_tested_at
 * @property string|null $last_test_result
 * @property Carbon|null $next_test_due_date
 */
final class GovControl extends BaseEnterpriseModel
{
    public const array Types = ['preventive', 'detective', 'corrective'];

    public const array Frequencies = ['continuous', 'daily', 'weekly', 'monthly', 'quarterly', 'annual', 'ad_hoc'];

    public const array Statuses = ['draft', 'active', 'retired'];

    /**
     * Allowed status changes: from => list of targets.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        'draft' => ['active', 'retired'],
        'active' => ['retired'],
        'retired' => ['active'],
    ];

    /**
     * Months between two tests of a control, by the frequency it runs with: a control that runs very often is tested on
     * a sample every quarter, a monthly one every six months, the others once a year.
     *
     * @var array<string, int>
     */
    public const array TestIntervalMonths = [
        'continuous' => 3,
        'daily' => 3,
        'weekly' => 3,
        'monthly' => 6,
        'quarterly' => 12,
        'annual' => 12,
        'ad_hoc' => 12,
    ];

    protected $table = 'gov_controls';

    protected function casts(): array
    {
        return [
            'last_tested_at' => 'date',
            'next_test_due_date' => 'date',
        ];
    }

    public function isTestOverdue(): bool
    {
        return $this->status === 'active' && $this->next_test_due_date !== null && $this->next_test_due_date->endOfDay()->isPast();
    }

    /**
     * @return HasMany<GovControlTestRun, $this>
     */
    public function testRuns(): HasMany
    {
        return $this->hasMany(GovControlTestRun::class, 'control_id');
    }

    /**
     * @return BelongsToMany<GovRisk, $this>
     */
    public function risks(): BelongsToMany
    {
        return $this->belongsToMany(GovRisk::class, 'gov_risk_controls', 'control_id', 'risk_id')->wherePivotNull('deleted_at');
    }
}
