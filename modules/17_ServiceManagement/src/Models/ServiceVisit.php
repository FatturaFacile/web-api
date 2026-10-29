<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected function visitNumber(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return $meta['visit_number'] ?? ('VIS-'.substr((string) ($attributes['id'] ?? ''), -8));
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['visit_number'] = (string) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    protected function serviceAppointmentId(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['appointment_id'] ?? $value,
            set: fn ($value) => ['appointment_id' => $value]
        );
    }

    protected function workSummary(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['visit_summary'] ?? $value ?? '',
            set: fn ($value) => ['visit_summary' => $value]
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return $meta['status'] ?? (! empty($attributes['check_out_at']) ? 'completed' : 'in_progress');
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['status'] = (string) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    protected function customerFeedback(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return $meta['customer_feedback'] ?? '';
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['customer_feedback'] = (string) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class, 'work_order_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(ServiceAppointment::class, 'appointment_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(ServiceTechnician::class, 'technician_id');
    }
}
