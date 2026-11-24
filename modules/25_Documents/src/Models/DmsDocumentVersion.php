<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\DocumentTrust\Exceptions\PreservationImmutableException;
use Modules\DocumentTrust\Services\PreservationLock;

/**
 * One immutable file of a document. `attachment_id` is set when the file belongs to an existing attachment: the version
 * only points at it, and the file is never copied or purged from here.
 */
final class DmsDocumentVersion extends BaseEnterpriseModel
{
    protected $table = 'dms_document_versions';

    /**
     * @var list<string>
     */
    protected $hidden = ['path', 'disk'];

    /**
     * The versions of a preserved document are immutable: none is added, changed or deleted.
     */
    protected static function booted(): void
    {
        $guard = static function (self $version): void {
            if (PreservationLock::documentIsPreserved((string) $version->document_id)) {
                throw new PreservationImmutableException('This document is part of a closed preservation package and its versions cannot change.');
            }
        };

        self::creating($guard);
        self::updating($guard);
        self::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'size' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DmsDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DmsDocument::class, 'document_id');
    }
}
