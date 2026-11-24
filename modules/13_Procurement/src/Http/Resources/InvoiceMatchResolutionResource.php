<?php

declare(strict_types=1);

namespace Modules\Procurement\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Procurement\Models\InvoiceMatchResolution;

/**
 * @mixin InvoiceMatchResolution
 */
final class InvoiceMatchResolutionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'run_id' => $this->run_id,
            'action' => $this->action->value,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'reason' => $this->reason,
            'actor_id' => $this->actor_id,
            'actor_name' => $this->actor_id !== null ? User::query()->whereKey($this->actor_id)->value('name') : null,
            'context' => $this->context ?? [],
            'resolved_at' => $this->resolved_at->toIso8601String(),
        ];
    }
}
