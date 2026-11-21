<?php

declare(strict_types=1);

namespace Modules\Hse\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Hse\Infrastructure\Models\AccidentClaimDraft;

/**
 * @property AccidentClaimDraft $resource
 */
final class AccidentClaimDraftResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $draft = $this->resource;

        return [
            'id' => $draft->id,
            'incident_report_id' => $draft->incident_report_id,
            'jurisdiction' => $draft->jurisdiction->value,
            'schema_version' => $draft->schema_version,
            'declaration_type' => $draft->declaration_type,
            'status' => $draft->status,
            'payload' => $draft->payload_json,
        ];
    }
}
