<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksAuditUsers;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A role of the tenant, or a platform system role when tenant_id is null.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_system
 */
final class Role extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasUuids, SoftDeletes, TracksAuditUsers;

    protected $table = 'roles';

    protected $fillable = ['tenant_id', 'key', 'name', 'description', 'is_system'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    /**
     * @return HasMany<RolePermission, $this>
     */
    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class, 'role_id');
    }

    /**
     * @return HasMany<AbacRuleRecord, $this>
     */
    public function abacRules(): HasMany
    {
        return $this->hasMany(AbacRuleRecord::class, 'role_id');
    }

    /**
     * @return HasMany<FieldPolicy, $this>
     */
    public function fieldPolicies(): HasMany
    {
        return $this->hasMany(FieldPolicy::class, 'role_id');
    }

    /**
     * @return HasMany<RoleAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class, 'role_id');
    }
}
