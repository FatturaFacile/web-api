<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One scheduled inspection: the checklist of its template is copied at generation time and filled in when the
 * inspection is completed. A failed item raises a service request.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $template_id
 * @property string $number
 * @property string $title
 * @property string|null $building_id
 * @property string|null $space_id
 * @property string|null $assignee_id
 * @property Carbon $due_date
 * @property string $status
 * @property string|null $result
 * @property list<array<string, mixed>> $checklist
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property string|null $completed_by
 * @property string|null $notes
 * @property string|null $service_request_id
 */
final class FacilityInspection extends BaseEnterpriseModel
{
    /** @var list<string> */
    public const array Statuses = ['scheduled', 'in_progress', 'completed', 'cancelled'];

    /** @var list<string> */
    public const array OpenStatuses = ['scheduled', 'in_progress'];

    protected $table = 'fac_inspections';

    protected function casts(): array
    {
        return [
            'checklist' => 'array',
            'due_date' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FacilityInspectionTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(FacilityInspectionTemplate::class, 'template_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OpenStatuses, true);
    }
}
