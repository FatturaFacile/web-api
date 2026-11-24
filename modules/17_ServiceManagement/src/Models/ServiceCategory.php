<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ServiceCategory extends BaseEnterpriseModel
{
    protected $table = 'esm_service_categories';

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'translations' => 'array',
            'metadata' => 'array',
        ];
    }

    protected function type(): Attribute
    {
        return Attribute::make(
            get: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];

                return $meta['type'] ?? 'service';
            },
            set: function ($value, $attributes) {
                $meta = json_decode($attributes['metadata'] ?? '{}', true) ?: [];
                $meta['type'] = (string) $value;

                return ['metadata' => json_encode($meta)];
            }
        );
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id');
    }

    public function slaPolicies(): HasMany
    {
        return $this->hasMany(SLAPolicy::class, 'service_category_id');
    }
}
