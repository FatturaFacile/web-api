<?php

declare(strict_types=1);

namespace Modules\Documents\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\Documents\Enums\OcrStatus;

/**
 * One OCR run over one version of a document: the engine that read it, the text and the extraction status.
 *
 * @property string $id
 * @property string $document_id
 * @property string $document_version_id
 * @property OcrStatus $status
 * @property ?string $engine
 * @property ?string $text_content
 * @property ?int $page_count
 * @property ?string $confidence
 * @property ?string $failure_reason
 * @property int $attempts
 * @property ?string $requested_by
 * @property ?Carbon $started_at
 * @property ?Carbon $finished_at
 */
final class DmsOcrJob extends BaseEnterpriseModel
{
    protected $table = 'dms_ocr_jobs';

    protected function casts(): array
    {
        return [
            'status' => OcrStatus::class,
            'page_count' => 'integer',
            'confidence' => 'decimal:2',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DmsDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(DmsDocument::class, 'document_id');
    }

    /**
     * @return BelongsTo<DmsDocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DmsDocumentVersion::class, 'document_version_id');
    }
}
