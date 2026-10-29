<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\Contact;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class ProjectResource extends BaseEnterpriseModel
{
    protected $table = 'project_resources';

    protected function casts(): array
    {
        return [
            'internal_hourly_cost' => 'decimal:2',
            'billing_hourly_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    public function capacityProfile(): HasOne
    {
        return $this->hasOne(ResourceCapacityProfile::class, 'resource_id');
    }

    public function calendars(): HasMany
    {
        return $this->hasMany(ResourceCalendar::class, 'resource_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ResourceAllocation::class, 'resource_id');
    }
}
