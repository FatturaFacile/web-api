<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model of the dms document (tenant scoped where it has a tenant_id).
 */
final class DmsDocument extends BaseEnterpriseModel
{
    protected $table = 'dms_documents';

    /**
     * The generated full-text column is internal.
     *
     * @var list<string>
     */
    protected $hidden = ['search_vector', 'search_text'];

    protected function casts(): array
    {
        return [
            'access_roles' => 'array',
            'metadata' => 'array',
            'retention_until' => 'date',
            'legal_hold' => 'boolean',
            'legal_hold_at' => 'datetime',
            'current_version' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DmsFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DmsFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo<DmsRetentionPolicy, $this>
     */
    public function retentionPolicy(): BelongsTo
    {
        return $this->belongsTo(DmsRetentionPolicy::class, 'retention_policy_id');
    }

    /**
     * @return HasMany<DmsDocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DmsDocumentVersion::class, 'document_id')->orderByDesc('version_number');
    }

    /**
     * @return HasMany<DmsDocumentLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(DmsDocumentLink::class, 'document_id');
    }

    /**
     * @return BelongsToMany<DmsTag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(DmsTag::class, 'dms_document_tag', 'document_id', 'tag_id')->withPivot('tenant_id');
    }
}
