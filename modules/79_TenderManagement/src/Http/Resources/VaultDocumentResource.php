<?php

declare(strict_types=1);

namespace Modules\TenderManagement\Http\Resources;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\TenderManagement\Models\QualificationVaultDocument;
use Modules\TenderManagement\Services\VaultStatusResolver;

/**
 * @mixin QualificationVaultDocument
 */
final class VaultDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolver = app(VaultStatusResolver::class);
        $today = CarbonImmutable::today();

        return [
            'id' => $this->id,
            'doc_type' => $this->doc_type->value,
            'jurisdiction' => $this->doc_type->isItalian() ? 'IT' : 'CH',
            'title' => $this->title,
            'document_number' => $this->document_number,
            'issuing_body' => $this->issuing_body,
            'soa_categories_classifications' => $this->soa_categories_classifications ?? [],
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'intermediate_triennial_check_date' => $this->intermediate_triennial_check_date?->toDateString(),
            'fvoe20_synced' => $this->fvoe20_synced,
            'fvoe20_synced_at' => $this->fvoe20_synced_at?->toIso8601String(),
            'numeric_value' => $this->numeric_value,
            'file_name' => $this->file_name,
            'has_file' => $this->file_path !== null,
            'verification_status' => $resolver->statusAt($this->resource, $today)->value,
            'days_to_expiry' => $resolver->daysToExpiry($this->resource, $today),
            'effective_expiry_date' => $resolver->effectiveExpiry($this->resource)?->toDateString(),
            'renewed_from_id' => $this->renewed_from_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
