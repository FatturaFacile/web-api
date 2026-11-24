<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Services\MesFeedback;

use Illuminate\Support\Facades\DB;

/**
 * The inbox of the events of the MES Core: an event id is processed once, whatever the number of deliveries.
 */
final readonly class MesInbox
{
    /**
     * Claims the event for processing; false when it was already received.
     */
    public function claim(string $eventId, string $tenantId, string $eventType): bool
    {
        return DB::table('mfg_mes_inbox')->insertOrIgnore([
            'event_id' => $eventId,
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'processed_at' => now(),
        ]) > 0;
    }
}
