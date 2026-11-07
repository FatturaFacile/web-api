<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ServiceManagement\Models\RoutingRule;

/**
 * @mixin RoutingRule
 */
final class RoutingRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'position' => (int) $this->position,
            'is_active' => (bool) $this->is_active,
            'stop_processing' => (bool) $this->stop_processing,
            'conditions' => $this->conditions ?? (object) [],
            'actions' => $this->actions ?? (object) [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
