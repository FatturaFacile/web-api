<?php

declare(strict_types=1);

namespace Modules\Quoting\Infrastructure\Services;

use Illuminate\Support\Carbon;
use Modules\Quoting\Infrastructure\Models\CpqAuditEvent;
use Modules\Quoting\Infrastructure\Models\CpqQuote;

/**
 * Hash-chained audit log of a quote: event_hash = sha256(previous_hash | sequence | event | actor | ip | payload | time).
 */
final class QuoteAuditTrail
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(CpqQuote $quote, string $event, ?string $actor = null, ?string $ip = null, array $payload = []): CpqAuditEvent
    {
        $last = CpqAuditEvent::query()->where('quote_id', $quote->id)->orderByDesc('sequence')->first();
        $sequence = ($last instanceof CpqAuditEvent ? $last->sequence : 0) + 1;
        $occurredAt = Carbon::now('UTC');

        return CpqAuditEvent::query()->create([
            'tenant_id' => $quote->tenant_id,
            'quote_id' => $quote->id,
            'sequence' => $sequence,
            'event' => $event,
            'actor' => $actor,
            'ip_address' => $ip,
            'payload' => $payload,
            'previous_hash' => $last instanceof CpqAuditEvent ? $last->event_hash : null,
            'event_hash' => $this->hash($last instanceof CpqAuditEvent ? $last->event_hash : null, $sequence, $event, $actor, $ip, $payload, $occurredAt),
            'occurred_at' => $occurredAt,
        ]);
    }

    /** Whether every event of the quote still matches its hash and links to the previous one. */
    public function verify(CpqQuote $quote): bool
    {
        $previous = null;

        foreach (CpqAuditEvent::query()->where('quote_id', $quote->id)->orderBy('sequence')->get() as $event) {
            $expected = $this->hash($previous, $event->sequence, $event->event, $event->actor, $event->ip_address, (array) $event->payload, $event->occurred_at);

            if ($event->previous_hash !== $previous || ! hash_equals($expected, $event->event_hash)) {
                return false;
            }

            $previous = $event->event_hash;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hash(?string $previous, int $sequence, string $event, ?string $actor, ?string $ip, array $payload, Carbon $at): string
    {
        return hash('sha256', implode('|', [
            $previous ?? '', $sequence, $event, $actor ?? '', $ip ?? '',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), $at->copy()->utc()->format('Y-m-d\TH:i:s'),
        ]));
    }
}
