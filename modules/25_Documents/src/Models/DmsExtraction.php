<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Documents\Enums\ExtractionStatus;

/**
 * Eloquent model of the dms extraction (tenant scoped where it has a tenant_id).
 */
final class DmsExtraction extends BaseEnterpriseModel
{
    protected $table = 'dms_extractions';

    protected function casts(): array
    {
        return [
            'status' => ExtractionStatus::class,
            'proposed_fields' => 'array',
            'confirmed_fields' => 'array',
            'decided_at' => 'datetime',
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
