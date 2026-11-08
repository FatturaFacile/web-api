<?php

declare(strict_types=1);

namespace Modules\Projects\Models;

use App\Models\Contact;
use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A resource that works on projects (a person, a vehicle, equipment, a subcontractor). For a person it holds the
 * default hourly cost and billing rate, used when no rate card matches, and the weekly capacity through its profile.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $company_id
 * @property string $resource_type
 * @property string|null $user_id
 * @property string|null $contact_id
 * @property string $name
 * @property string $internal_hourly_cost
 * @property string $billing_hourly_rate
 * @property bool $is_active
 */
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return HasOne<ResourceCapacityProfile, $this>
     */
    public function capacityProfile(): HasOne
    {
        return $this->hasOne(ResourceCapacityProfile::class, 'resource_id');
    }

    /**
     * @return HasMany<ResourceCalendar, $this>
     */
    public function calendars(): HasMany
    {
        return $this->hasMany(ResourceCalendar::class, 'resource_id');
    }

    /**
     * @return HasMany<ResourceAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(ResourceAllocation::class, 'resource_id');
    }
}
