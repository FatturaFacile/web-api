<?php

declare(strict_types=1);

namespace Modules\Intercompany\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Intercompany\Models\PartyLink;
use Modules\Intercompany\Support\TenantNames;

/**
 * @mixin PartyLink
 */
final class PartyLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'group_id' => (string) $this->group_id,
            'entity_tenant_id' => (string) $this->entity_tenant_id,
            'counterparty_tenant_id' => (string) $this->counterparty_tenant_id,
            'contact_id' => (string) $this->contact_id,
            'role' => $this->role,
            'entity_name' => TenantNames::of((string) $this->entity_tenant_id),
            'counterparty_name' => TenantNames::of((string) $this->counterparty_tenant_id),
        ];
    }
}
