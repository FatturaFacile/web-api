<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An engineering change order on a BOM: draft, submitted, approved or rejected, implemented. Implementation creates
 * the new BOM revision from the component changes of the payload and makes it effective from the effective date.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $eco_number
 * @property string $title
 * @property string $reason
 * @property string|null $description
 * @property string $change_type
 * @property string $status
 * @property Carbon|null $effective_date
 * @property string|null $bom_id
 * @property string|null $resulting_bom_id
 * @property string|null $requested_by
 * @property Carbon|null $submitted_at
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property string|null $rejected_by
 * @property Carbon|null $rejected_at
 * @property string|null $rejection_reason
 * @property Carbon|null $implemented_at
 * @property array<string, mixed>|null $change_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class MfgEngineeringChangeOrder extends BaseEnterpriseModel
{
    protected $table = 'mfg_engineering_change_orders';

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'implemented_at' => 'datetime',
            'change_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function bom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'bom_id');
    }

    /**
     * @return BelongsTo<MfgBom, $this>
     */
    public function resultingBom(): BelongsTo
    {
        return $this->belongsTo(MfgBom::class, 'resulting_bom_id');
    }

    /**
     * Whether the change was approved and waits for implementation.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Whether the change has been implemented.
     */
    public function isImplemented(): bool
    {
        return $this->status === 'implemented';
    }
}
