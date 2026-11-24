<?php

declare(strict_types=1);

namespace Modules\Communication\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Communication\Models\CampaignRecipient;

/**
 * A row of the send log of a campaign.
 *
 * @mixin CampaignRecipient
 */
final class CampaignRecipientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'contact_id' => $this->contact_id,
            'lead_id' => $this->lead_id,
            'address' => $this->address,
            'status' => $this->status,
            'suppression_reason' => $this->suppression_reason,
            'delivery_log_id' => $this->delivery_log_id,
            'error' => $this->error,
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
