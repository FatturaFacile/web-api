<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A photo attached to a field work order by the technician app. `client_uuid` (unique per tenant) makes the upload
 * idempotent; the file lives on the private `local` disk under `field-service/photos/{tenant}/{work order}/`.
 *
 * @property string $id
 * @property string $work_order_id
 * @property string $client_uuid
 * @property string $path
 * @property string|null $stage before|during|after
 * @property string|null $caption
 * @property Carbon|null $captured_at
 * @property string|null $uploaded_by
 */
final class WorkOrderPhoto extends BaseEnterpriseModel
{
    protected $table = 'esm_work_order_photos';

    protected function casts(): array
    {
        return ['captured_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }
}
