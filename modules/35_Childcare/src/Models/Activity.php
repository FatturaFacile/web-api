<?php

declare(strict_types=1);

namespace Modules\Childcare\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An extracurricular activity on a weekday, optionally with a monthly fee.
 *
 * @property string $name
 * @property int $capacity
 * @property string $monthly_fee
 * @property string $tax_rate
 */
final class Activity extends ChildcareRecord
{
    protected $table = 'cc_activities';

    protected function casts(): array
    {
        return ['weekday' => 'integer', 'capacity' => 'integer', 'monthly_fee' => 'decimal:2', 'tax_rate' => 'decimal:2'];
    }

    /**
     * @return HasMany<ActivityEnrolment, $this>
     */
    public function enrolments(): HasMany
    {
        return $this->hasMany(ActivityEnrolment::class, 'activity_id');
    }
}
