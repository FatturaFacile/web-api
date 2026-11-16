<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a leaving employee says about the reasons of the departure. The feedback is personal data, encrypted at rest.
 *
 * @property string $reason_code
 * @property string $employee_id
 */
final class ExitInterview extends BaseEnterpriseModel
{
    protected $table = 'hr_exit_interviews';

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    protected function casts(): array
    {
        return [
            'conducted_on' => 'date:Y-m-d',
            'would_recommend' => 'boolean',
            'eligible_for_rehire' => 'boolean',
            'satisfaction_rating' => 'integer',
            'feedback' => 'encrypted',
        ];
    }
}
