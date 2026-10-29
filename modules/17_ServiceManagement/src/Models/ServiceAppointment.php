<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ServiceAppointment extends BaseEnterpriseModel
{
    protected $table = 'esm_service_appointments';

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'metadata' => 'array',
        ];
    }

    protected function scheduledStart(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['start_time'] ?? $value,
            set: fn ($value) => ['start_time' => $value]
        );
    }

    protected function scheduledEnd(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['end_time'] ?? $value,
            set: fn ($value) => ['end_time' => $value]
        );
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
