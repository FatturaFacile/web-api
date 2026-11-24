<?php

declare(strict_types=1);

namespace Modules\Collaboration\Models;

use App\Models\User;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment on a record of the collaboration registry, with the members it mentions.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $commentable_type
 * @property string $commentable_id
 * @property string $user_id
 * @property string $body
 * @property list<string>|null $mentioned_user_ids
 * @property Carbon|null $edited_at
 */
final class CollabComment extends BaseEnterpriseModel
{
    protected $table = 'collab_comments';

    protected function casts(): array
    {
        return [
            'mentioned_user_ids' => 'array',
            'edited_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
