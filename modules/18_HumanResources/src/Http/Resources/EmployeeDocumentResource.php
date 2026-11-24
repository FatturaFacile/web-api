<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\HumanResources\Models\EmployeeDocument;

/**
 * Metadata of a personnel file document; the storage path is never exposed.
 *
 * @mixin EmployeeDocument
 */
final class EmployeeDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var EmployeeDocument $document */
        $document = $this->resource;

        return [
            'id' => $document->id,
            'employee_id' => $document->employee_id,
            'document_type' => $document->document_type,
            'title' => $document->title,
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'issue_date' => $document->issue_date?->format('Y-m-d'),
            'expiry_date' => $document->expiry_date?->format('Y-m-d'),
            'retention_until' => $document->retention_until?->format('Y-m-d'),
            'contains_health_data' => (bool) $document->contains_health_data,
            'is_confidential' => (bool) $document->is_confidential,
            'visible_to_employee' => (bool) $document->visible_to_employee,
            'status' => $document->status,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }
}
