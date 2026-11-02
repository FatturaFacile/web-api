<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TimeEntryEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TimeEntryEvent */
class TimeEntryEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'type' => $this->type->value,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'recorded_at' => $this->recorded_at->toIso8601String(),
            'recorded_by' => $this->recorded_by,
        ];
    }
}
