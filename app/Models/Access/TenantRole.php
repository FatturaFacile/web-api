<?php

declare(strict_types=1);

namespace App\Models\Access;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A role an administrator defines for the tenant: one of the system roles as base, plus a module level
 * (blocked, read, write) for the modules it changes.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $base_role
 * @property array<string, string>|null $modules
 */
class TenantRole extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'tenant_roles';

    protected $fillable = ['tenant_id', 'key', 'name', 'description', 'base_role', 'modules'];

    protected function casts(): array
    {
        return ['modules' => 'array'];
    }
}
