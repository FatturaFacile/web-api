<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TicketTimeEntry extends BaseEnterpriseModel
{
    protected $table = 'esm_ticket_time_entries';

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'decimal:2',
            'hourly_cost' => 'decimal:2',
            'hourly_rate' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'total_charge' => 'decimal:2',
            'is_billable' => 'boolean',
            'metadata' => 'array',
        ];
    }

    protected function hoursSpent(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => (float) ($attributes['hours'] ?? $value ?? 0.0),
            set: fn ($value) => ['hours' => $value]
        );
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(ServiceTechnician::class, 'technician_id');
    }
}
