<?php

declare(strict_types=1);

namespace App\Models\Access;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\Foundation\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A role of one company: a system role (`base_role`) that decides the default module access, plus a module => level
 * map (blocked, read, write) that is written to the permissions of every membership holding the role.
 *
 * @property string $id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $base_role
 * @property array<string, string> $modules
 */
class TenantCustomRole extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'tenant_custom_roles';

    protected $fillable = ['tenant_id', 'key', 'name', 'description', 'base_role', 'modules'];

    protected function casts(): array
    {
        return ['modules' => 'array'];
    }
}
