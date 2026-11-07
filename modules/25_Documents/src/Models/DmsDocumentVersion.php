<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
