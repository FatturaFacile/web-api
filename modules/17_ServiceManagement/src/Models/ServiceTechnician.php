<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ServiceTechnician extends BaseEnterpriseModel
{
    protected $table = 'esm_service_technicians';

    protected function casts(): array
    {
        return [
            'hourly_cost_rate' => 'decimal:2',
            'hourly_billable_rate' => 'decimal:2',
            'is_available' => 'boolean',
            'skills' => 'array',
            'certifications' => 'array',
            'metadata' => 'array',
        ];
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => trim(($attributes['first_name'] ?? '').' '.($attributes['last_name'] ?? '')),
            set: function ($value) {
                $parts = explode(' ', (string) $value, 2);

                return [
                    'first_name' => $parts[0] ?? 'Technician',
                    'last_name' => $parts[1] ?? '',
                ];
            }
        );
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['current_status'] ?? $value ?? 'available',
            set: fn ($value) => ['current_status' => $value]
        );
    }

    protected function hourlyBillingRate(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['hourly_billable_rate'] ?? $value ?? 0.0),
            set: fn ($value) => ['hourly_billable_rate' => $value]
        );
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'team_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TicketTimeEntry::class, 'technician_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(ServiceAppointment::class, 'technician_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ServiceVisit::class, 'technician_id');
    }
}
