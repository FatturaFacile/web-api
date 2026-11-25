<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\StatutoryFinancials\Models\NotesWorkspace;

/**
 * ExplanatoryNotesAnhangWorkspace.
 *
 * @mixin NotesWorkspace
 */
final class NotesResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dossier_id' => $this->dossier_id,
            'taxonomy_version' => $this->taxonomy_version,
            'quantitative_tables' => $this->quantitative_tables ?? new \stdClass,
            'narrative_text_sections' => $this->narrative_text_sections ?? [],
            'sources' => $this->sources ?? new \stdClass,
            'populated_at' => $this->populated_at?->toIso8601String(),
        ];
    }
}
