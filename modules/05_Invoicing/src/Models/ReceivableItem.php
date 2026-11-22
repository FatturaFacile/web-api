<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use App\Models\Contact;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One open item of the customer ledger: an amount the customer owes on one due date (an installment of an issued
 * invoice, or the whole invoice when it has none). The open amount is the original amount less the allocations that
 * settle it, see ReceivableReconciliationService.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $customer_id
 * @property string|null $invoice_id
 * @property int $sequence
 * @property string $document_type
 * @property string|null $document_number
 * @property string $currency
 * @property string $original_amount
 * @property string $open_amount
 * @property Carbon $issue_date
 * @property Carbon $due_date
 * @property Carbon|null $value_date
 * @property string $status
 * @property string|null $dispute_reason
 * @property Carbon|null $disputed_at
 * @property Carbon|null $deleted_at
 */
final class ReceivableItem extends BaseEnterpriseModel
{
    public const string StatusOpen = 'open';

    public const string StatusPartiallyPaid = 'partially_paid';

    public const string StatusPaid = 'paid';

    public const string StatusOverdue = 'overdue';

    public const string StatusDisputed = 'disputed';

    public const string StatusWrittenOff = 'written_off';

    protected $table = 'receivable_items';

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'open_amount' => 'decimal:2',
            'issue_date' => 'date',
            'due_date' => 'date',
            'value_date' => 'date',
            'disputed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'customer_id');
    }

    /**
     * @return HasMany<ReceivableAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReceivableAllocation::class, 'receivable_item_id');
    }

    /**
     * Whether something is still owed on the item.
     */
    public function isOpen(): bool
    {
        return bccomp((string) $this->open_amount, '0', 2) > 0 && $this->status !== self::StatusWrittenOff;
    }

    /**
     * The status shown to the user: an open or partly paid item past its due date is overdue, unless it is disputed.
     */
    public function effectiveStatus(CarbonInterface $today): string
    {
        if (in_array($this->status, [self::StatusOpen, self::StatusPartiallyPaid], true) && $this->due_date->lt($today->copy()->startOfDay())) {
            return self::StatusOverdue;
        }

        return (string) $this->status;
    }

    /**
     * Whole days past the due date at `$today`; 0 when not yet due.
     */
    public function daysPastDue(CarbonInterface $today): int
    {
        $due = $this->due_date->copy()->startOfDay();
        $day = $today->copy()->startOfDay();

        return $due->lt($day) ? (int) $due->diffInDays($day, true) : 0;
    }
}
