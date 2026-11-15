<?php

declare(strict_types=1);

namespace Modules\Collaboration\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The link of a tag to a record. A link is removed for good (force delete) so the pair can be attached again.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $tag_id
 * @property string $taggable_type
 * @property string $taggable_id
 */
final class CollabTaggable extends BaseEnterpriseModel
{
    protected $table = 'collab_taggables';

    /**
     * @return BelongsTo<CollabTag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(CollabTag::class, 'tag_id');
    }
}
