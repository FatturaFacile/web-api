<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An employee following a sequence for a period.
 */
final class ShiftSequenceAssignment extends BaseEnterpriseModel
{
    protected $table = 'hr_shift_sequence_assignments';

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'offset_days' => 'integer'];
    }

    /**
     * @return BelongsTo<ShiftSequence, $this>
     */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(ShiftSequence::class, 'shift_sequence_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
