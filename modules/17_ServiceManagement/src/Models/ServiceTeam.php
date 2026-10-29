<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ServiceTeam extends BaseEnterpriseModel
{
    protected $table = 'esm_service_teams';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    public function teamLead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    public function technicians(): HasMany
    {
        return $this->hasMany(ServiceTechnician::class, 'team_id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_team_id');
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'service_team_id');
    }
}
