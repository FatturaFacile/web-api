<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Associations\Enums\InstalmentStatus;
use Modules\Associations\Support\Money;

/**
 * @property string $id
 * @property string $amount
 * @property string $paid_amount
 * @property InstalmentStatus $status
 * @property CarbonImmutable $due_on
 * @property-read FeeAssignment $assignment
 */
final class FeeInstalment extends AssociationRecord
{
    protected $table = 'assoc_fee_instalments';

    protected function casts(): array
    {
        return [
            'status' => InstalmentStatus::class,
            'due_on' => 'immutable_date',
            'amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(FeeAssignment::class, 'fee_assignment_id');
    }

    public function outstanding(): string
    {
        return Money::sub((string) $this->amount, (string) $this->paid_amount);
    }
}
