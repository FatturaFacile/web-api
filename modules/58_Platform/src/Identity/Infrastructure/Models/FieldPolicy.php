<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Infrastructure\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\TracksAuditUsers;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Platform\Identity\Domain\Enums\FieldVisibility;

/**
 * @property string $id
 * @property string|null $tenant_id
 * @property string $role_id
 * @property string $resource
 * @property string $field
 * @property FieldVisibility $visibility
 * @property string $kind
 */
final class FieldPolicy extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasUuids, TracksAuditUsers;

    protected $table = 'field_policies';

    protected $fillable = ['tenant_id', 'role_id', 'resource', 'field', 'visibility', 'kind'];

    protected function casts(): array
    {
        return ['visibility' => FieldVisibility::class];
    }
}
