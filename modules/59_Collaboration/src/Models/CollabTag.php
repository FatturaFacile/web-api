<?php

declare(strict_types=1);

namespace Modules\Collaboration\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A label shared by the whole company, attachable to any record of the collaboration registry.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property string|null $color
 */
final class CollabTag extends BaseEnterpriseModel
{
    protected $table = 'collab_tags';

    /**
     * @return HasMany<CollabTaggable, $this>
     */
    public function taggables(): HasMany
    {
        return $this->hasMany(CollabTaggable::class, 'tag_id');
    }
}
