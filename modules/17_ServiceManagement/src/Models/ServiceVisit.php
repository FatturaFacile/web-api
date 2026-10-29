<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ServiceVisit extends BaseEnterpriseModel
{
    protected $table = 'esm_service_visits';

    protected function casts(): array
    {
        return [
            'check_in_at' => 'datetime',
            'check_out_at' => 'datetime',
            'duration_minutes' => 'integer',
            'customer_present' => 'boolean',
            'is_successful' => 'boolean',
            'location_check_in' => 'array',
            'location_check_out' => 'array',
            'metadata' => 'array',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ServiceAppointment::class, 'appointment_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(ServiceTechnician::class, 'technician_id');
    }
}
