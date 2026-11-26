<?php

declare(strict_types=1);

namespace App\Http\Resources\Audit;

use Enterprise\AuditLedger\Application\DTOs\ResourceHistoryItemData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property ResourceHistoryItemData $resource
 */
final class ResourceHistoryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;

        return [
            'id' => $item->id,
            'sequence' => $item->sequence,
            'occurred_at' => $item->occurredAt,
            'action' => $item->action,
            'category' => $item->category,
            'display_actor' => $item->displayActor,
            'display_impersonator' => $item->displayImpersonator,
            'channel' => $item->channel,
            'changed_fields' => $item->changedFields,
            'changes' => $item->changes,
        ];
    }
}
