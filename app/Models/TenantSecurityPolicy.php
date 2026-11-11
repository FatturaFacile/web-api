<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantSecurityPolicyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The password, lockout, session, second factor and SSO-only rules of one tenant. A tenant can only tighten the platform
 * defaults (see TenantSecurityPolicies); a tenant without a row follows the platform defaults.
 *
 * @property string $id
 * @property string $tenant_id
 * @property int $password_min_length
 * @property bool $password_require_mixed_case
 * @property bool $password_require_numbers
 * @property bool $password_require_symbols
 * @property int $password_history_count
 * @property int|null $password_max_age_days
 * @property int $lockout_max_attempts
 * @property int $lockout_minutes
 * @property int|null $session_idle_timeout_minutes
 * @property int|null $max_concurrent_sessions
 * @property list<string>|null $two_factor_roles
 * @property bool $sso_only
 */
class TenantSecurityPolicy extends Model
{
    /** @use HasFactory<TenantSecurityPolicyFactory> */
    use BelongsToTenant, HasFactory, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'password_min_length' => 'integer',
            'password_require_mixed_case' => 'boolean',
            'password_require_numbers' => 'boolean',
            'password_require_symbols' => 'boolean',
            'password_history_count' => 'integer',
            'password_max_age_days' => 'integer',
            'lockout_max_attempts' => 'integer',
            'lockout_minutes' => 'integer',
            'session_idle_timeout_minutes' => 'integer',
            'max_concurrent_sessions' => 'integer',
            'two_factor_roles' => 'array',
            'sso_only' => 'boolean',
        ];
    }
}
