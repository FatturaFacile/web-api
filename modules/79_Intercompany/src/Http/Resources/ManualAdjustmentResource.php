<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\ManualAdjustment;

/**
 * @mixin ManualAdjustment
 */
final class ManualAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'run_id' => (string) $this->run_id,
            'reference' => $this->reference,
            'reason' => $this->reason,
            'status' => $this->status,
            'amount' => (string) $this->amount,
            'lines' => $this->lines,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
