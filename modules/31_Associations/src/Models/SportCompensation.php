<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A compensation paid to a sports collaborator for a month, with the contribution and withholding figures as
 * computed by the payroll consultant.
 *
 * @property string $collaborator_id
 * @property string $gross_amount
 * @property-read SportCollaborator $collaborator
 */
final class SportCompensation extends AssociationRecord
{
    protected $table = 'assoc_sport_compensations';

    protected function casts(): array
    {
        return [
            'paid_on' => 'date',
            'gross_amount' => 'decimal:2',
            'contribution_base' => 'decimal:2',
            'contribution_amount' => 'decimal:2',
            'withholding_base' => 'decimal:2',
            'withholding_amount' => 'decimal:2',
            'hours' => 'decimal:2',
        ];
    }

    public function collaborator(): BelongsTo
    {
        return $this->belongsTo(SportCollaborator::class, 'collaborator_id');
    }
}
