<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Documents\Models\DmsPreservationChainLink;

/**
 * API representation of one link of the preservation hash chain.
 *
 * @mixin DmsPreservationChainLink
 */
final class PreservationChainLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'sequence' => $this->sequence,
            'package_id' => (string) $this->package_id,
            'package_hash' => $this->package_hash,
            'previous_link_hash' => $this->previous_link_hash,
            'link_hash' => $this->link_hash,
            'anchored_by' => $this->anchored_by,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
