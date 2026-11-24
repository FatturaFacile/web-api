<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Problem extends BaseEnterpriseModel
{
    protected $table = 'esm_problems';

    protected function casts(): array
    {
        return [
            'is_known_error' => 'boolean',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'linked_incident_ids' => 'array',
            'metadata' => 'array',
        ];
    }

    protected function rootCauseAnalysis(): Attribute
    {
        return Attribute::make(
            get: fn ($value, $attributes) => $attributes['root_cause'] ?? $value ?? '',
            set: fn ($value) => ['root_cause' => $value]
        );
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(ChangeRequest::class, 'problem_id');
    }
}
