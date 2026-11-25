<?php

declare(strict_types=1);

namespace Modules\Returns\Models;

use App\Models\Contact;
use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Advance Replacement / Cross-Shipping Order (Mod 232): ships a replacement unit immediately while opening a
 * return commitment for the defective unit with an N-day SLA window.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $replacement_number
 * @property string|null $customer_return_id
 * @property string|null $rma_id
 * @property string $contact_id
 * @property string|null $original_invoice_id
 * @property string $faulty_product_id
 * @property string|null $faulty_serial_number
 * @property string $replacement_product_id
 * @property string|null $replacement_serial_number
 * @property string $replacement_unit_type
 * @property string|null $refurbished_item_id
 * @property string $quantity
 * @property string|null $warehouse_id
 * @property string|null $outbound_delivery_note_id
 * @property string|null $outbound_stock_movement_id
 * @property string $status
 * @property Carbon $shipped_on
 * @property int $sla_days
 * @property Carbon $return_due_on
 * @property Carbon|null $defective_received_at
 * @property string $penalty_amount
 * @property string $currency
 * @property string|null $penalty_invoice_id
 * @property string|null $penalty_invoice_number
 * @property Carbon|null $billed_at
 * @property string|null $notes
 * @property string|null $created_by
 */
final class AdvanceReplacement extends BaseEnterpriseModel
{
    public const string StatusPendingDefectiveReturn = 'pending_defective_return';

    public const string StatusDefectiveReceived = 'defective_received';

    public const string StatusOverdueBilled = 'overdue_billed';

    public const string StatusCancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [
        self::StatusPendingDefectiveReturn,
        self::StatusDefectiveReceived,
        self::StatusOverdueBilled,
        self::StatusCancelled,
    ];

    public const string UnitNew = 'new';

    public const string UnitRefurbished = 'refurbished';

    /** @var list<string> */
    public const array UnitTypes = [
        self::UnitNew,
        self::UnitRefurbished,
    ];

    protected $table = 'advance_replacements';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'shipped_on' => 'date',
            'sla_days' => 'integer',
            'return_due_on' => 'date',
            'defective_received_at' => 'datetime',
            'penalty_amount' => 'decimal:2',
            'billed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<CustomerReturn, $this>
     */
    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class, 'customer_return_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function faultyProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'faulty_product_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function replacementProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'replacement_product_id');
    }
}
