<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use Modules\DebtCollection\Models\Event;

/**
 * Writes the chronological dunning history of a customer shown in the customer master record.
 */
final class HistoryRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $customerId, string $event, string $subjectType, string $subjectId, ?int $level = null, ?string $amount = null, ?string $currency = null, array $payload = [], ?string $actorId = null): Event
    {
        return Event::create([
            'customer_id' => $customerId,
            'event' => $event,
            'level' => $level,
            'amount' => $amount,
            'currency' => $currency,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $payload === [] ? null : $payload,
            'actor_id' => $actorId,
            'occurred_at' => now(),
        ]);
    }
}
