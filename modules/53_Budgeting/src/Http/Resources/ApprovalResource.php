<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Budgeting\Models\BudgetApproval;

/**
 * A step of the approval of a version.
 *
 * @mixin BudgetApproval
 */
final class ApprovalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version_id' => $this->version_id,
            'action' => $this->action,
            'from_status' => $this->from_status,
            'to_status' => $this->to_status,
            'user_id' => $this->user_id,
            'comment' => $this->comment,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
