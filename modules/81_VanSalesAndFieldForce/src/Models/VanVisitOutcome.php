<?php

declare(strict_types=1);

namespace Modules\VanSalesAndFieldForce\Models;

use App\Models\Contact;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\VanSalesAndFieldForce\Domain\Enums\ThermalPrinterProfile;
use Modules\VanSalesAndFieldForce\Domain\Enums\VanFiscalDocumentType;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitMode;
use Modules\VanSalesAndFieldForce\Domain\Enums\VisitOutcomeStatus;

/**
 * Outcome of an in-field customer stop: van sale, pre-sale order, no-sale, reschedule, or credit block,
 * including definitive offline-safe fiscal sectional numbers, GS1-128 variable-weight lines,
 * returns/empties, overdue credit unblock collections, and ESC/POS or ZPL print payload.
 *
 * @property string $field_route_plan_id
 * @property string $van_warehouse_id
 * @property string|null $van_load_manifest_id
 * @property string $contact_id
 * @property int $stop_sequence
 * @property VisitMode $visit_mode
 * @property VisitOutcomeStatus $outcome_status
 * @property string|null $no_sale_reason_code
 * @property Carbon|null $rescheduled_to
 * @property VanFiscalDocumentType|null $document_type
 * @property string|null $fiscal_sectional_code
 * @property string|null $definitive_document_number
 * @property string|null $invoice_id
 * @property string|null $delivery_note_id
 * @property string|null $sales_order_id
 * @property string $sdi_transmission_status
 * @property bool $qr_bill_generated
 * @property string|null $qr_bill_reference
 * @property bool $credit_check_passed
 * @property string $credit_limit_amount
 * @property string $exposure_before_visit
 * @property string $overdue_amount_before_visit
 * @property bool $credit_blocked_initially
 * @property bool $unblocked_by_collection
 * @property string|null $unblocking_payment_id
 * @property string $collected_overdue_amount
 * @property array<int, array<string, mixed>>|null $sale_lines
 * @property array<int, array<string, mixed>>|null $return_lines
 * @property array<int, array<string, mixed>>|null $empties_movements
 * @property array<int, array<string, mixed>>|null $collections
 * @property string $total_net
 * @property string $total_tax
 * @property string $total_gross
 * @property string $empties_net_deposit_amount
 * @property string $returns_deduction_amount
 * @property string $total_collected_amount
 * @property string|null $payment_method
 * @property string $currency
 * @property ThermalPrinterProfile $printer_format
 * @property string|null $thermal_print_payload
 * @property string|null $client_idempotency_key
 * @property Carbon|null $offline_created_at
 * @property Carbon|null $synced_at
 * @property int $sync_version
 * @property string|null $sync_conflict_notes
 * @property string|null $gps_latitude
 * @property string|null $gps_longitude
 * @property Carbon $visited_at
 * @property string|null $notes
 */
final class VanVisitOutcome extends BaseEnterpriseModel
{
    protected $table = 'van_visit_outcomes';

    protected function casts(): array
    {
        return [
            'stop_sequence' => 'integer',
            'visit_mode' => VisitMode::class,
            'outcome_status' => VisitOutcomeStatus::class,
            'rescheduled_to' => 'date',
            'document_type' => VanFiscalDocumentType::class,
            'qr_bill_generated' => 'boolean',
            'credit_check_passed' => 'boolean',
            'credit_limit_amount' => 'decimal:2',
            'exposure_before_visit' => 'decimal:2',
            'overdue_amount_before_visit' => 'decimal:2',
            'credit_blocked_initially' => 'boolean',
            'unblocked_by_collection' => 'boolean',
            'collected_overdue_amount' => 'decimal:2',
            'sale_lines' => 'array',
            'return_lines' => 'array',
            'empties_movements' => 'array',
            'collections' => 'array',
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'empties_net_deposit_amount' => 'decimal:2',
            'returns_deduction_amount' => 'decimal:2',
            'total_collected_amount' => 'decimal:2',
            'printer_format' => ThermalPrinterProfile::class,
            'offline_created_at' => 'datetime',
            'synced_at' => 'datetime',
            'sync_version' => 'integer',
            'gps_latitude' => 'decimal:7',
            'gps_longitude' => 'decimal:7',
            'visited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<FieldRoutePlan, $this>
     */
    public function routePlan(): BelongsTo
    {
        return $this->belongsTo(FieldRoutePlan::class, 'field_route_plan_id');
    }

    /**
     * @return BelongsTo<VanWarehouse, $this>
     */
    public function vanWarehouse(): BelongsTo
    {
        return $this->belongsTo(VanWarehouse::class, 'van_warehouse_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }
}
