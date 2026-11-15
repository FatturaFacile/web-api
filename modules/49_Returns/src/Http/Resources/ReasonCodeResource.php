<?php

declare(strict_types=1);

namespace Modules\Returns\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Returns\Models\ReturnReasonCode;

/**
 * A reason code of the returns settings.
 *
 * @mixin ReturnReasonCode
 */
final class ReasonCodeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'label' => $this->label,
            'is_defect' => $this->is_defect,
            'is_active' => $this->is_active,
        ];
    }
}
