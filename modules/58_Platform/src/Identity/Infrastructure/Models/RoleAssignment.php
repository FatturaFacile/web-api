<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksAuditUsers;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A role held by a user, for the whole tenant (company_id null) or for one company.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role_id
 * @property string|null $company_id
 */
final class RoleAssignment extends Model
{
    use BelongsToTenant, HasUuids, TracksAuditUsers;

    protected $table = 'role_assignments';

    protected $fillable = ['tenant_id', 'user_id', 'role_id', 'company_id'];

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }
}
