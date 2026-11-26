<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One SCIM operation of the identity provider: the resource (`user` or `group`), the operation (`create`, `replace`,
 * `patch`, `delete`, `members`), the outcome and, for a failure, the error. Only identifiers and display names are kept.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $resource
 * @property string $operation
 * @property string $status
 * @property string|null $resource_id
 * @property string|null $resource_name
 * @property string|null $scim_token_id
 * @property string|null $error
 * @property Carbon|null $created_at
 */
class ScimProvisioningLog extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];
}
