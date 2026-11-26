<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A SCIM 2.0 `Group` of a tenant, as pushed by its identity provider. The display name is unique within the tenant.
 * Membership is stored here so the group can be read back; the role a group grants comes from ScimGroupMapping.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $display_name
 * @property string|null $external_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScimGroup extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'scim_group_members', 'scim_group_id', 'user_id')->withTimestamps();
    }
}
