<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\Models;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use Carbon\CarbonInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Modules\AccountsPayable\Enums\OpenItemKind;
use Modules\AccountsPayable\Enums\OpenItemStatus;

/**
 * One open item of the supplier ledger (partita aperta): an invoice we owe, or a credit note the supplier owes us, on one
 * due date. The amounts are always positive; the kind gives the direction. `open_amount` is what is still to settle:
 * the original amount less the payments, the cash discounts and the credit notes offset.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $supplier_id
 * @property string|null $passive_invoice_id
 * @property string $source
 * @property string $kind
 * @property string $document_number
 * @property Carbon $document_date
 * @property Carbon $due_date
 * @property string $currency
 * @property string $original_amount
 * @property string $paid_amount
 * @property string $discount_taken_amount
 * @property string $open_amount
 * @property string|null $creditor_iban
 * @property string $status
 * @property string|null $status_before_hold
 * @property string|null $hold_reason
 * @property Carbon|null $held_at
 * @property string|null $held_by
 * @property string|null $skonto_percentage
 * @property int|null $skonto_days
 * @property string|null $notes
 */
final class ApOpenItem extends BaseEnterpriseModel
{
    protected $table = 'ap_open_items';

    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'due_date' => 'date',
            'original_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'discount_taken_amount' => 'decimal:2',
            'open_amount' => 'decimal:2',
            'skonto_percentage' => 'decimal:2',
            'skonto_days' => 'integer',
            'held_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'supplier_id');
    }

    /**
     * @return BelongsTo<PassiveInvoice, $this>
     */
    public function passiveInvoice(): BelongsTo
    {
        return $this->belongsTo(PassiveInvoice::class, 'passive_invoice_id');
    }

    /**
     * @return HasMany<ApOpenItemEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ApOpenItemEvent::class, 'open_item_id')->orderBy('occurred_at')->orderBy('id');
    }

    public function statusEnum(): OpenItemStatus
    {
        return OpenItemStatus::from((string) $this->status);
    }

    public function isCreditNote(): bool
    {
        return $this->kind === OpenItemKind::CreditNote->value;
    }

    public function isHeld(): bool
    {
        return $this->statusEnum()->isHold();
    }

    public function hasOpenBalance(): bool
    {
        return bccomp((string) $this->open_amount, '0', 2) > 0;
    }

    /**
     * Whole days past the due date at `$today`, negative while the item is not yet due.
     */
    public function daysPastDue(CarbonInterface $today): int
    {
        $due = $this->due_date->copy()->startOfDay();
        $day = $today->copy()->startOfDay();

        return (int) round($due->diffInDays($day, false));
    }

    /**
     * Whether the supplier was already paid something on the item.
     */
    public function isPartiallyPaid(): bool
    {
        return bccomp((string) $this->paid_amount, '0', 2) > 0;
    }
}
