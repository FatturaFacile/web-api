<?php

declare(strict_types=1);

namespace Modules\Contracts\Models;

use App\Models\User;
use Database\Factories\ContractObligationFactory;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An obligation or a milestone of a contract with a due date, an owner and a reminder. A milestone can carry an amount
 * (a payment or a deliverable value). The stored status is `open`, `completed` or `waived`; an open item past its due
 * date is overdue, which is derived and never stored.
 *
 * @property string $id
 * @property string $contract_kind
 * @property string $contract_id
 * @property string $type
 * @property string $status
 * @property Carbon $due_date
 * @property ?string $description
 * @property string $responsible_party
 * @property ?string $owner_user_id
 * @property ?string $amount
 * @property ?string $currency
 * @property int $remind_days_before
 * @property ?Carbon $reminded_at
 * @property ?Carbon $completed_at
 * @property ?string $completion_note
 * @property string $title
 */
#[UseFactory(ContractObligationFactory::class)]
final class ContractObligation extends BaseEnterpriseModel
{
    /**
     * @var list<string>
     */
    public const array Types = ['obligation', 'milestone'];

    protected $table = 'contract_obligations';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'amount' => 'decimal:2',
            'remind_days_before' => 'integer',
            'reminded_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Open and past its due date, on the calendar of the given day.
     */
    public function isOverdue(\DateTimeInterface $today): bool
    {
        return $this->status === 'open' && $this->due_date->toDateString() < $today->format('Y-m-d');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }
}
