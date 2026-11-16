<?php

declare(strict_types=1);

namespace Modules\Quality\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Quality\Models\QmsComplaint;

/**
 * A customer complaint with its investigation, links and reply.
 *
 * @mixin QmsComplaint
 */
final class ComplaintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'customer_name' => $this->customer_name,
            'contact_id' => $this->contact_id,
            'channel' => $this->channel,
            'reference' => $this->reference,
            'product_description' => $this->product_description,
            'description' => $this->description,
            'severity' => $this->severity,
            'status' => $this->status,
            'owner_id' => $this->owner_id,
            'root_cause' => $this->root_cause,
            'nonconformity_id' => $this->nonconformity_id,
            'capa_action_id' => $this->capa_action_id,
            'reply_text' => $this->reply_text,
            'replied_by' => $this->replied_by,
            'closed_by' => $this->closed_by,
            'closure_notes' => $this->closure_notes,
            'received_at' => $this->received_at->toDateString(),
            'replied_at' => $this->replied_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
