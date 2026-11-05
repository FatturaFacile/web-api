<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
        'first_name',
        'last_name',
        'email',
        'password',
        'phone',
        'locale',
        'is_active',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'is_platform_admin' => 'boolean',
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

    /**
     * @return HasOne<TenantAccessRequest, $this>
     */
    public function tenantAccessRequest(): HasOne
    {
        return $this->hasOne(TenantAccessRequest::class);
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

    public function currentTenant(): ?Tenant
    {
        return app(TenantContext::class)->getTenant() ?? $this->tenants()->first();
    }

    public function getTenantIdAttribute(): ?string
    {
        if (isset($this->attributes['tenant_id']) && $this->attributes['tenant_id'] !== null) {
            return (string) $this->attributes['tenant_id'];
        }

        return app(TenantContext::class)->getTenantId() ?? $this->tenants()->first()?->id;
    }

    public function getCurrentTenantIdAttribute(): ?string
    {
        return $this->tenant_id;
    }
}
