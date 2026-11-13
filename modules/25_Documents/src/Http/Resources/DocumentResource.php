<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentLink;
use Modules\Documents\Models\DmsTag;

/**
 * API representation of the document.
 *
 * @mixin DmsDocument
 */
final class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'folder_id' => $this->folder_id,
            'folder_name' => $this->whenLoaded('folder', fn (): ?string => $this->folder?->name),
            'title' => $this->title,
            'description' => $this->description,
            'document_type' => $this->document_type,
            'status' => $this->status,
            'current_version' => (int) $this->current_version,
            'access_roles' => $this->access_roles ?? [],
            'metadata' => (object) ($this->metadata ?? []),
            'retention_policy_id' => $this->retention_policy_id,
            'retention_until' => $this->retention_until?->toDateString(),
            'legal_hold' => (bool) $this->legal_hold,
            'legal_hold_reason' => $this->legal_hold_reason,
            'is_checked_out' => $this->isCheckedOut(),
            'checked_out_by' => $this->checked_out_by,
            'checked_out_at' => $this->checked_out_at?->toIso8601String(),
            'checkout_note' => $this->checkout_note,
            'tags' => $this->whenLoaded('tags', fn (): array => $this->tags->map(static fn (DmsTag $tag): array => ['id' => (string) $tag->id, 'name' => $tag->name])->all()),
            'links' => $this->whenLoaded('links', fn (): array => $this->links->map(static fn (DmsDocumentLink $link): array => ['id' => (string) $link->id, 'linkable_type' => $link->linkable_type, 'linkable_id' => (string) $link->linkable_id])->all()),
            'latest_version' => $this->whenLoaded('latestVersion', fn (): ?DocumentVersionResource => $this->latestVersion !== null ? new DocumentVersionResource($this->latestVersion) : null),
            'versions' => DocumentVersionResource::collection($this->whenLoaded('versions')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
