<?php

declare(strict_types=1);

namespace Modules\HumanResources\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\HumanResources\Services\Shifts\ShiftSequencePattern;

/**
 * An ordered list of shift type ids; a null step is a free day.
 *
 * @property ShiftSequencePattern $pattern
 * @property list<string|null> $steps
 */
final class ShiftSequence extends BaseEnterpriseModel
{
    protected $table = 'hr_shift_sequences';

    protected function casts(): array
    {
        return [
            'pattern' => ShiftSequencePattern::class,
            'steps' => 'array',
            'skip_holidays' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<ShiftSequenceAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftSequenceAssignment::class, 'shift_sequence_id');
    }
}
