<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A certification of an employee (or a catalogue entry without employee), with its expiry date.
 *
 * @property Carbon|null $issue_date
 * @property Carbon|null $expiry_date
 */
final class Certification extends BaseEnterpriseModel
{
    protected $table = 'hr_certifications';

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'expiry_date' => 'date',
            'is_mandatory' => 'boolean',
            'renewal_alert_sent_at' => 'datetime',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(TrainingCourse::class, 'course_id');
    }
}
