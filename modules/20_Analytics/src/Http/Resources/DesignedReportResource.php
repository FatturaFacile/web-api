<?php

declare(strict_types=1);

namespace Modules\Analytics\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Analytics\Models\DesignedReport;

/**
 * API representation of a designed report.
 *
 * @mixin DesignedReport
 */
final class DesignedReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isOwner = (string) $this->user_id === (string) $request->user()?->id;

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'description' => $this->description,
            'dataset' => $this->dataset,
            'spec' => $this->spec,
            'is_shared' => $this->is_shared,
            'shared_with' => $isOwner ? $this->shared_with : [],
            'is_owner' => $isOwner,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
