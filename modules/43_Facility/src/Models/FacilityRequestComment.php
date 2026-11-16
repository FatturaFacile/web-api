<?php

declare(strict_types=1);

namespace Modules\Facility\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment on a service request. Internal comments are visible to the facility staff only.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $service_request_id
 * @property string|null $author_id
 * @property string $body
 * @property bool $is_internal
 * @property Carbon|null $created_at
 */
final class FacilityRequestComment extends BaseEnterpriseModel
{
    protected $table = 'fac_request_comments';

    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
