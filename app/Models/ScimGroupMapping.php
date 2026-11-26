<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Maps the display name of a SCIM group to a role of the tenant: members of the group get that role (see ScimGroups).
 * Unique per tenant and group name.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $group_name
 * @property string $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ScimGroupMapping extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];
}
