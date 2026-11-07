<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class DmsFolder extends BaseEnterpriseModel
{
    protected $table = 'dms_folders';

    protected function casts(): array
    {
        return ['access_roles' => 'array'];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<DmsDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(DmsDocument::class, 'folder_id');
    }
}
