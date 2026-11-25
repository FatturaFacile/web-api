<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Advanced Customer Return (RMA) aggregate for Reverse Logistics (Mod 232).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $rma_id
 * @property string $rma_number
 * @property string $rma_barcode
 * @property string|null $return_tracking_number
 * @property string|null $carrier
 * @property string $contact_id
 * @property string|null $invoice_id
 * @property string|null $delivery_note_id
 * @property string|null $sales_order_id
 * @property string|null $warehouse_id
 * @property string $reason_code
 * @property string $channel
 * @property string $status
 * @property Carbon $requested_on
 * @property bool $within_return_window
 * @property bool $within_warranty_window
 * @property bool $window_overridden
 * @property int $days_since_purchase
 * @property bool $restocking_fee_applicable
 * @property string $restocking_fee_percent
 * @property string $restocking_fee_amount
 * @property string $gross_return_amount
 * @property string $net_refundable_amount
 * @property array<int, string>|null $photo_urls
 * @property string|null $customer_notes
 * @property string|null $internal_notes
 * @property string|null $resolution_type
 * @property string|null $credit_note_id
 * @property string|null $credit_note_reference
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $received_at
 * @property Carbon|null $inspected_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property string|null $created_by
 */
final class CustomerReturn extends BaseEnterpriseModel
{
    public const string StatusRequested = 'requested';

    public const string StatusApproved = 'approved';

    public const string StatusReceived = 'received';

    public const string StatusInspected = 'inspected';

    public const string StatusResolved = 'resolved';

    public const string StatusRejected = 'rejected';

    public const string StatusClosed = 'closed';

    /** @var list<string> */
    public const array Statuses = [
        self::StatusRequested,
        self::StatusApproved,
        self::StatusReceived,
        self::StatusInspected,
        self::StatusResolved,
        self::StatusRejected,
        self::StatusClosed,
    ];

    public const string ChannelBackoffice = 'backoffice';

    public const string ChannelPortalB2B = 'portal_b2b';

    public const string ChannelPortalB2C = 'portal_b2c';

    /** @var list<string> */
    public const array Channels = [
        self::ChannelBackoffice,
        self::ChannelPortalB2B,
        self::ChannelPortalB2C,
    ];

    protected $table = 'customer_returns';

    protected function casts(): array
    {
        return [
            'requested_on' => 'date',
            'within_return_window' => 'boolean',
            'within_warranty_window' => 'boolean',
            'window_overridden' => 'boolean',
            'days_since_purchase' => 'integer',
            'restocking_fee_applicable' => 'boolean',
            'restocking_fee_percent' => 'decimal:2',
            'restocking_fee_amount' => 'decimal:2',
            'gross_return_amount' => 'decimal:2',
            'net_refundable_amount' => 'decimal:2',
            'photo_urls' => 'array',
            'approved_at' => 'datetime',
            'received_at' => 'datetime',
            'inspected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<CustomerReturnLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CustomerReturnLine::class, 'customer_return_id');
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

    /**
     * @return BelongsTo<DeliveryNote, $this>
     */
    public function deliveryNote(): BelongsTo
    {
        return $this->belongsTo(DeliveryNote::class);
    }

    /**
     * @return HasMany<AdvanceReplacement, $this>
     */
    public function advanceReplacements(): HasMany
    {
        return $this->hasMany(AdvanceReplacement::class, 'customer_return_id');
    }

    /**
     * @return HasMany<ReturnGradingInspection, $this>
     */
    public function gradingInspections(): HasMany
    {
        return $this->hasMany(ReturnGradingInspection::class, 'customer_return_id');
    }
}
