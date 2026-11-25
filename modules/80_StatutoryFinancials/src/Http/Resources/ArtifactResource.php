<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\StatutoryFinancials\Models\Artifact;

/**
 * @mixin Artifact
 */
final class ArtifactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dossier_id' => $this->dossier_id,
            'kind' => $this->kind,
            'file_name' => $this->file_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'pdfa_conformance' => $this->pdfa_conformance,
            'verification' => $this->verification,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
