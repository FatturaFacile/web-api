<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Database\Factories\AssociationsShiftFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A service shift that needs a number of volunteers.
 *
 * @property string $id
 * @property int $required_volunteers
 * @property string $status
 */
#[UseFactory(AssociationsShiftFactory::class)]
final class Shift extends AssociationRecord
{
    public const string Planned = 'planned';

    public const string Cancelled = 'cancelled';

    protected $table = 'assoc_shifts';

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'required_volunteers' => 'integer'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class, 'shift_id');
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(ShiftAvailability::class, 'shift_id');
    }
}
