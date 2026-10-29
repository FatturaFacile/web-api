<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
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

    protected function teamType(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return $meta['team_type'] ?? 'support';
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['team_type'] = (string) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
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
