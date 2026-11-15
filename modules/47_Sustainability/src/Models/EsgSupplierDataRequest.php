<?php

declare(strict_types=1);

namespace Modules\Sustainability\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Procurement\Models\Supplier;

/**
 * A request for supplier-specific emission data (GHG Protocol Scope 3, supplier-specific method). The request travels
 * through a document requirement of the supplier portal (the supplier uploads its emission statement there); once
 * received, the reported emissions become a dedicated emission factor and an activity record of the period.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $reporting_period_id
 * @property string $supplier_id
 * @property string|null $document_requirement_id
 * @property string|null $submission_id
 * @property int $scope3_category
 * @property string $activity_type
 * @property string $unit
 * @property string $quantity
 * @property string $status
 * @property Carbon|null $due_date
 * @property string|null $reported_co2e_kg
 * @property string|null $calculation_method
 * @property string|null $emission_factor_id
 * @property string|null $activity_record_id
 * @property string|null $notes
 * @property string|null $requested_by
 * @property Carbon|null $received_at
 * @property Carbon|null $applied_at
 */
final class EsgSupplierDataRequest extends BaseEnterpriseModel
{
    public const string Requested = 'requested';

    public const string Received = 'received';

    public const string Applied = 'applied';

    public const string Declined = 'declined';

    public const string Cancelled = 'cancelled';

    /** @var list<string> */
    public const array Statuses = [self::Requested, self::Received, self::Applied, self::Declined, self::Cancelled];

    /** Code of the supplier portal document requirement the requests use. */
    public const string RequirementCode = 'ESG_SUPPLIER_EMISSIONS';

    protected $table = 'esg_supplier_data_requests';

    protected function casts(): array
    {
        return [
            'scope3_category' => 'integer',
            'quantity' => 'decimal:4',
            'due_date' => 'date',
            'reported_co2e_kg' => 'decimal:4',
            'received_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<EsgReportingPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(EsgReportingPeriod::class, 'reporting_period_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::Requested, self::Received], true);
    }
}
