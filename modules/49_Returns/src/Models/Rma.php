<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Contact;
use App\Models\Invoice;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A return merchandise authorisation: goods a customer sends back against an invoice or a sales order.
 *
 * requested -> approved -> received -> inspected -> resolved -> closed, and requested, approved or inspected -> rejected
 * -> closed. A line can be returned up to what the source document sold less what other returns already took back.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $rma_number
 * @property string $contact_id
 * @property string|null $invoice_id
 * @property string|null $sales_order_id
 * @property string|null $warehouse_id
 * @property string $status
 * @property string $reason_code
 * @property Carbon $requested_on
 * @property bool $window_overridden
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|null $received_at
 * @property Carbon|null $inspected_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property string|null $resolution_type
 * @property string|null $refund_amount
 * @property string|null $credit_note_reference
 * @property string|null $resolution_notes
 * @property string|null $notes
 * @property string|null $created_by
 */
final class Rma extends BaseEnterpriseModel
{
    public const string Requested = 'requested';

    public const string Approved = 'approved';

    public const string Received = 'received';

    public const string Inspected = 'inspected';

    public const string Resolved = 'resolved';

    public const string Rejected = 'rejected';

    public const string Closed = 'closed';

    /** @var list<string> */
    public const array Statuses = [self::Requested, self::Approved, self::Received, self::Inspected, self::Resolved, self::Rejected, self::Closed];

    /**
     * Statuses of the returns whose quantities count against what can still be returned.
     *
     * @var list<string>
     */
    public const array CountingStatuses = [self::Requested, self::Approved, self::Received, self::Inspected, self::Resolved, self::Closed];

    /**
     * Statuses of a return still waiting for somebody to act on it.
     *
     * @var list<string>
     */
    public const array OpenStatuses = [self::Requested, self::Approved, self::Received, self::Inspected];

    /**
     * Allowed moves of the state machine.
     *
     * @var array<string, list<string>>
     */
    public const array Transitions = [
        self::Requested => [self::Approved, self::Rejected],
        self::Approved => [self::Received, self::Rejected],
        self::Received => [self::Inspected],
        self::Inspected => [self::Resolved, self::Rejected],
        self::Resolved => [self::Closed],
        self::Rejected => [self::Closed],
        self::Closed => [],
    ];

    public const string ResolutionRefund = 'refund';

    public const string ResolutionCreditNote = 'credit_note';

    public const string ResolutionReplacement = 'replacement';

    public const string ResolutionRepair = 'repair';

    /** @var list<string> */
    public const array ResolutionTypes = [self::ResolutionRefund, self::ResolutionCreditNote, self::ResolutionReplacement, self::ResolutionRepair];

    protected $table = 'returns_rmas';

    protected function casts(): array
    {
        return [
            'requested_on' => 'date',
            'window_overridden' => 'boolean',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'refund_amount' => 'decimal:2',
        ];
    }

    public function canMoveTo(string $status): bool
    {
        return in_array($status, self::Transitions[$this->status] ?? [], true);
    }

    /**
     * @return HasMany<RmaLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RmaLine::class, 'rma_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
