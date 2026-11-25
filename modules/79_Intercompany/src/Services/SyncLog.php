<?php

declare(strict_types=1);

namespace Modules\Intercompany\Services;

use Modules\Intercompany\Enums\SyncEventType;
use Modules\Intercompany\Models\SyncEvent;
use Modules\Intercompany\Models\TransactionLink;

/**
 * Append-only audit log of the mirror engine: nothing is ever updated or deleted, a correction is a new event.
 */
final readonly class SyncLog
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(string $ownerTenantId, string $groupId, ?TransactionLink $link, SyncEventType $event, ?string $messageKey = null, array $context = [], ?string $amount = null, ?string $userId = null): SyncEvent
    {
        return SyncEvent::withoutGlobalScopes()->create([
            'tenant_id' => $ownerTenantId,
            'group_id' => $groupId,
            'transaction_id' => $link?->id,
            'event' => $event->value,
            'message_key' => $messageKey,
            'context' => $context,
            'amount' => $amount,
            'occurred_at' => now(),
            'user_id' => $userId,
        ]);
    }
}
