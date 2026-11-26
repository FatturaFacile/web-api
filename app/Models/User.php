<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use App\Models\Pivots\TenantMembership;
use App\Services\Auth\UserSessions;
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
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $failed_login_attempts
 * @property Carbon|null $login_locked_until
 * @property int $lockout_streak
 * @property Carbon|null $two_factor_email_confirmed_at
 * @property array<string, mixed>|null $ui_preferences
 */
class User extends Authenticatable implements AuditableEntityInterface
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes;

    use HasImmutableAuditTrail;

    /** @var list<string> */
    protected array $auditExclude = ['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_used_step', 'failed_login_attempts', 'login_locked_until', 'lockout_streak'];

    public const array UiPreferenceDefaults = ['theme_preference' => 'system', 'ui_density' => 'comfortable', 'reduced_motion' => null];

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
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_last_used_step',
    ];

    /**
     * A deactivated user loses every API token and browser session at once, so nothing opened before stops working.
     */
    protected static function booted(): void
    {
        static::updated(static function (User $user): void {
            if ($user->wasChanged('is_active') && $user->is_active === false) {
                app(UserSessions::class)->revokeAll($user);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'password_reset_required' => 'boolean',
            'last_password_reset_at' => 'datetime',
            'is_platform_admin' => 'boolean',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_email_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
            'failed_login_attempts' => 'integer',
            'lockout_streak' => 'integer',
            'login_locked_until' => 'datetime',
            'ui_preferences' => 'array',
        ];
    }

    /**
     * Whether a tenant membership of the user was suspended (SCIM deactivation by the identity provider).
     */
    public function hasSuspendedTenantMembership(): bool
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')->wherePivotNotNull('suspended_at')->exists();
    }

    /**
     * The tenants the user can act in: a membership suspended by the identity provider (SCIM deactivation) is not one.
     *
     * @return BelongsToMany<Tenant, $this, TenantMembership, 'pivot'>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_user')
            ->using(TenantMembership::class)
            ->withPivot(['id', 'role', 'permissions'])
            ->wherePivotNull('suspended_at')
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

    /**
     * The interface preferences live in the ui_preferences document (see UserPreferencesController), not in columns.
     */
    public function getThemePreferenceAttribute(): string
    {
        return (string) $this->uiPreference('theme_preference');
    }

    public function getUiDensityAttribute(): string
    {
        return (string) $this->uiPreference('ui_density');
    }

    public function getReducedMotionAttribute(): ?bool
    {
        $value = $this->uiPreference('reduced_motion');

        return $value === null ? null : (bool) $value;
    }

    private function uiPreference(string $key): mixed
    {
        $stored = is_array($this->ui_preferences) ? $this->ui_preferences : [];

        return array_key_exists($key, $stored) ? $stored[$key] : self::UiPreferenceDefaults[$key];
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
