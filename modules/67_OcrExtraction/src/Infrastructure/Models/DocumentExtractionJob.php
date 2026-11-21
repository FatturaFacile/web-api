<?php

declare(strict_types=1);

namespace Modules\OcrExtraction\Infrastructure\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\OcrExtraction\Domain\Enums\DocumentCategory;
use Modules\OcrExtraction\Domain\Enums\ExtractionJobStatus;
use Modules\OcrExtraction\Domain\Enums\ExtractionMethod;
use Modules\OcrExtraction\Domain\Enums\OcrProviderType;
use Modules\OcrExtraction\Domain\Enums\SourceChannel;

/**
 * One document going through the AI Inbox (tenant scoped).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $dms_document_id
 * @property SourceChannel $source_channel
 * @property DocumentCategory $document_category
 * @property ExtractionMethod|null $extraction_method
 * @property OcrProviderType|null $ocr_provider_used
 * @property string $overall_confidence_score
 * @property int $llm_tokens_used
 * @property array<string, mixed>|null $extracted_payload_json
 * @property array<string, array{page: int, x: float, y: float, width: float, height: float}>|null $bounding_boxes_json
 * @property array<string, mixed>|null $review_draft_json
 * @property string|null $vendor_id
 * @property list<string>|null $matched_purchase_order_ids_json
 * @property list<string>|null $matched_goods_receipt_ids_json
 * @property ExtractionJobStatus $status
 * @property string|null $failure_reason
 * @property string|null $requested_by_user_id
 * @property string|null $confirmed_by_user_id
 * @property Carbon|null $confirmed_at
 * @property string|null $resulting_entity_type
 * @property string|null $resulting_entity_id
 */
final class DocumentExtractionJob extends BaseEnterpriseModel
{
    protected $table = 'document_extraction_jobs';

    protected function casts(): array
    {
        return [
            'source_channel' => SourceChannel::class,
            'document_category' => DocumentCategory::class,
            'extraction_method' => ExtractionMethod::class,
            'ocr_provider_used' => OcrProviderType::class,
            'status' => ExtractionJobStatus::class,
            'overall_confidence_score' => 'decimal:2',
            'llm_tokens_used' => 'integer',
            'extracted_payload_json' => 'array',
            'bounding_boxes_json' => 'array',
            'review_draft_json' => 'array',
            'matched_purchase_order_ids_json' => 'array',
            'matched_goods_receipt_ids_json' => 'array',
            'confirmed_at' => 'datetime',
        ];
    }
}
