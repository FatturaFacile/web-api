<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksAuditUsers;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $role_id
 * @property string $permission
 */
final class RolePermission extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasUuids, TracksAuditUsers;

    protected $table = 'role_permissions';

    protected $fillable = ['tenant_id', 'role_id', 'permission'];
}
