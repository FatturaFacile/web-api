<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contracts\Models\ContractTemplate;

/**
 * A contract template with its variables and ordered clauses.
 *
 * @mixin ContractTemplate
 */
final class ContractTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contract_kind' => $this->contract_kind,
            'status' => $this->status,
            'variables' => $this->variables ?? [],
            'clauses' => $this->whenLoaded('templateClauses', fn (): array => $this->templateClauses->map(static fn ($entry): array => ['id' => $entry->id, 'clause_id' => $entry->clause_id, 'position' => $entry->position, 'is_optional' => $entry->is_optional, 'code' => $entry->clause?->code, 'title' => $entry->clause?->title])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
