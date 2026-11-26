<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use Enterprise\AuditLedger\Application\DTOs\AuditEntryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property AuditEntryData $resource
 */
final class AuditEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => $entry->id,
            'sequence' => $entry->sequence,
            'occurred_at' => $entry->occurredAt,
            'tenant_id' => $entry->tenantId,
            'company_id' => $entry->companyId,
            'category' => $entry->category,
            'module' => $entry->moduleCode,
            'action' => $entry->action,
            'resource_type' => $entry->resourceType,
            'resource_id' => $entry->resourceId,
            'actor_type' => $entry->actorType,
            'actor_ref' => $entry->actorRef,
            'display_actor' => $entry->displayActor,
            'impersonator_ref' => $entry->impersonatorRef,
            'display_impersonator' => $entry->displayImpersonator,
            'changes' => $entry->changes,
            'context' => $entry->context,
            'previous_hash' => $entry->previousHash,
            'hash' => $entry->hash,
            'key_version' => $entry->keyVersion,
            'hash_version' => $entry->hashVersion,
        ];
    }
}
