<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Contracts\Models\ContractDocumentVersion;

/**
 * A version of the text of a contract.
 *
 * @mixin ContractDocumentVersion
 */
final class DocumentVersionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contract_kind' => $this->contract_kind,
            'contract_id' => $this->contract_id,
            'version_no' => $this->version_no,
            'source' => $this->source,
            'template_id' => $this->template_id,
            'round_id' => $this->round_id,
            'author_id' => $this->author_id,
            'notes' => $this->notes,
            'body_hash' => $this->body_hash,
            'body' => $this->body,
            'open_comments' => $this->when(isset($this->open_comments_count), $this->open_comments_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
