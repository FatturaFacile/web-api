<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contracts\Models\ContractClause;

/**
 * A clause of the library with its current wording; the versions and fallbacks when loaded.
 *
 * @mixin ContractClause
 */
final class ClauseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'title' => $this->title,
            'category' => $this->category,
            'status' => $this->status,
            'current_version_no' => $this->current_version_no,
            'current_body' => $this->whenLoaded('versions', fn (): ?string => $this->versions->firstWhere('version_no', $this->current_version_no)?->body),
            'versions' => $this->whenLoaded('versions', fn (): array => $this->versions->map(static fn ($version): array => ['id' => $version->id, 'version_no' => $version->version_no, 'body' => $version->body, 'change_note' => $version->change_note, 'created_by' => $version->created_by, 'created_at' => $version->created_at?->toIso8601String()])->all()),
            'fallbacks' => $this->whenLoaded('fallbacks', fn (): array => $this->fallbacks->map(static fn ($fallback): array => ['id' => $fallback->id, 'rank' => $fallback->rank, 'title' => $fallback->title, 'body' => $fallback->body, 'requires_approval' => $fallback->requires_approval])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
