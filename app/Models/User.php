<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'locale',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->withPivot(['id', 'role', 'permissions'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<TimeEntry, $this>
     */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    public function belongsToTenant(string $tenantId): bool
    {
        return $this->tenants()->where('tenants.id', $tenantId)->exists();
    }

    public function getRoleInTenant(string $tenantId): ?string
    {
        $pivot = $this->tenants()->where('tenants.id', $tenantId)->first()?->pivot;

        return $pivot?->role;
    }

    /**
     * True when the user holds the permission slug in the tenant, either through the pivot permission list
     * (`*` grants everything) or through one of the given privileged roles.
     *
     * @param  list<string>  $grantingRoles
     */
    public function hasTenantPermission(?string $tenantId, string $slug, array $grantingRoles = ['owner', 'admin']): bool
    {
        if ($tenantId === null) {
            return false;
        }

        $pivot = $this->tenants()->where('tenants.id', $tenantId)->first()?->pivot;
        if ($pivot === null) {
            return false;
        }

        if (in_array($pivot->role, $grantingRoles, true)) {
            return true;
        }

        $permissions = is_string($pivot->permissions) ? json_decode($pivot->permissions, true) : $pivot->permissions;

        return is_array($permissions) && (in_array($slug, $permissions, true) || in_array('*', $permissions, true));
    }
}
