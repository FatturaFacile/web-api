<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Tenancy\TenantContext;
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
     * @return array<int, string>
     */
    public function tenantPermissionsFor(string $tenantId): array
    {
        $pivot = $this->tenants()->where('tenants.id', $tenantId)->first()?->pivot;

        if (! $pivot || ! isset($pivot->permissions)) {
            return [];
        }

        $permissions = $pivot->permissions;
        if (is_array($permissions)) {
            return array_values(array_filter(array_map('strval', $permissions), static fn (string $permission): bool => $permission !== ''));
        }

        $decoded = json_decode((string) $permissions, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $decoded), static fn (string $permission): bool => $permission !== ''));
    }

    public function hasAttendancePermission(string $permission, ?string $tenantId = null): bool
    {
        $tenantId ??= app(TenantContext::class)->getTenantId() ?? $this->tenants()->first()?->id;
        if ($tenantId === null) {
            return false;
        }

        $role = strtolower((string) ($this->getRoleInTenant($tenantId) ?? ''));
        $permissions = $this->tenantPermissionsFor($tenantId);

        if ($role === 'admin' || in_array('*', $permissions, true) || in_array('attendance.*', $permissions, true)) {
            return true;
        }

        $permissionAliases = [
            'view-all-attendance' => ['attendance.view_all', 'attendance.view-all', 'view-all-attendance'],
            'approve-attendance-corrections' => ['attendance.approve_corrections', 'attendance.approve-corrections', 'approve-attendance-corrections'],
        ];

        $allowedByRole = match ($permission) {
            'view-all-attendance' => in_array($role, ['manager', 'supervisor', 'hr_manager', 'team_lead'], true),
            'approve-attendance-corrections' => in_array($role, ['manager', 'supervisor', 'hr_manager', 'team_lead'], true),
            default => false,
        };

        if ($allowedByRole) {
            return true;
        }

        foreach ($permissionAliases[$permission] ?? [$permission] as $alias) {
            if (in_array($alias, $permissions, true)) {
                return true;
            }
        }

        return false;
    }
}
