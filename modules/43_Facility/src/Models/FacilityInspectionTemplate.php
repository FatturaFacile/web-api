<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * A recurring inspection: a checklist, a frequency and the next due date. The scheduler generates one inspection for
 * every due date. Checklist items are {key, label, type, required} with type pass_fail, number or text.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $building_id
 * @property string|null $space_id
 * @property string $frequency
 * @property int $interval_count
 * @property list<array{key: string, label: string, type: string, required: bool}> $checklist
 * @property string|null $assignee_id
 * @property Carbon $next_due_date
 * @property int $lead_days
 * @property Carbon|null $last_generated_on
 * @property bool $is_active
 * @property string|null $notes
 */
final class FacilityInspectionTemplate extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Frequencies = ['daily', 'weekly', 'monthly', 'quarterly', 'yearly'];

    /** @var list<string> */
    public const array ItemTypes = ['pass_fail', 'number', 'text'];

    protected $table = 'fac_inspection_templates';

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'interval_count' => 'integer',
            'lead_days' => 'integer',
            'next_due_date' => 'date',
            'last_generated_on' => 'date',
            'is_active' => 'boolean',
        ];
    }
}
