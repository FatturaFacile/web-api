<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AttendanceRecord extends BaseEnterpriseModel
{
    protected $table = 'hr_attendance_records';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'clock_in_at' => 'datetime',
            'clock_out_at' => 'datetime',
            'break_minutes' => 'integer',
            'regular_hours' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'night_hours' => 'decimal:2',
            'holiday_hours' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
