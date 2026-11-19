<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\CRM\Models\LeadSource;

/**
 * Never exposes webhook_secret or api_key_hash; freshly generated credentials are added by the controller
 * only in the create / rotate-secret responses.
 *
 * @mixin LeadSource
 */
final class LeadSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type ?? 'manual',
            'is_active' => $this->is_active,
            'allowed_origins' => $this->allowed_origins ?? [],
            'field_mapping' => $this->field_mapping ?? (object) [],
            'default_product_line' => $this->default_product_line,
            'webhook_url' => route('crm.webhooks.leads.store', ['sourceId' => $this->id]),
            'events_webhook_url' => route('crm.webhooks.leads.events', ['sourceId' => $this->id]),
            'api_key_prefix' => $this->api_key_prefix,
            'has_webhook_secret' => ! empty($this->getRawOriginal('webhook_secret')),
            'last_received_at' => $this->last_received_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
