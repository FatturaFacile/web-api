<?php

declare(strict_types=1);

namespace Modules\Governance\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The per-document segregation of duties rule of one kind of document.
 *
 * @property array{document_type: string, severity: string, is_active: bool, configured: bool} $resource
 */
final class SodDocumentRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'document_type' => $this->resource['document_type'],
            'severity' => $this->resource['severity'],
            'is_active' => $this->resource['is_active'],
            'configured' => $this->resource['configured'],
        ];
    }
}
