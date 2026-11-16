<?php

declare(strict_types=1);

namespace App\Models\Access;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A group of members of the tenant. A team with a custom role gives that role to every member it contains.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string|null $custom_role_id
 */
class TenantTeam extends Model
{
    use BelongsToTenant, HasUuids, SoftDeletes;

    protected $table = 'tenant_teams';

    protected $fillable = ['tenant_id', 'name', 'description', 'custom_role_id'];

    /**
     * @return BelongsTo<TenantRole, $this>
     */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(TenantRole::class, 'custom_role_id');
    }
}
