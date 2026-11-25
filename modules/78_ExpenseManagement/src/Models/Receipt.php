<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * An uploaded receipt: the stored file, its SHA-256 and what the OCR extracted. `document_id` points to the Mod 007 document
 * when the DMS is the document store.
 *
 * @property string|null $document_id
 * @property string $sha256
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string|null $ocr_provider
 * @property string|null $ocr_confidence
 * @property array<string, mixed>|null $ocr_payload
 */
final class Receipt extends BaseEnterpriseModel
{
    protected $table = 'exm_receipts';

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'ocr_confidence' => 'decimal:4',
            'ocr_payload' => 'array',
        ];
    }
}
