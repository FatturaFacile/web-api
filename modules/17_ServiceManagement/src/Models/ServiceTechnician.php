<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(ServiceTeam::class, 'team_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_technician_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'assigned_technician_id');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(ServiceAppointment::class, 'technician_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(ServiceVisit::class, 'technician_id');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(TicketTimeEntry::class, 'technician_id');
    }
}
