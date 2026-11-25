<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\ExpenseManagement\Models\ExpenseAuditLog;

/**
 * @mixin ExpenseAuditLog
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'expense_line_id' => $this->expense_line_id,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'actor_id' => $this->actor_id,
            'payload' => $this->payload ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
