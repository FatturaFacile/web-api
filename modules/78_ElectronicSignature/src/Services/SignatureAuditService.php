<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Services;

use Illuminate\Support\Facades\DB;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Models\SignatureAuditLog;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Models\SignatureRecipient;

/**
 * Writes and checks the immutable audit trail of an envelope. Every entry stores the SHA-256 of the previous one (the
 * hash chain), the UTC time, the actor with IP address and user agent, the hash of the document at that moment and, when
 * there is one, the receipt of the certification authority and the RFC 3161 time-stamp token. It is the evidence of a
 * dispute and part of the dossier that is archived with the signed document (conservazione a norma, GeBüV).
 */
final class SignatureAuditService
{
    public const string GenesisHash = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Appends an entry. The envelope row is locked so concurrent signers cannot fork the chain.
     *
     * @param  array{type?: string, id?: string|null, ip?: string|null, user_agent?: string|null}  $actor
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        SignatureEnvelope $envelope,
        AuditEvent $event,
        array $actor = [],
        ?SignatureRecipient $recipient = null,
        array $metadata = [],
        ?string $documentSha256 = null,
        ?string $receipt = null,
        ?string $timestampToken = null,
    ): SignatureAuditLog {
        return DB::transaction(function () use ($envelope, $event, $actor, $recipient, $metadata, $documentSha256, $receipt, $timestampToken): SignatureAuditLog {
            SignatureEnvelope::query()->whereKey($envelope->id)->lockForUpdate()->first();

            $last = SignatureAuditLog::query()->where('envelope_id', $envelope->id)->orderByDesc('sequence')->first();
            $sequence = ($last->sequence ?? 0) + 1;
            $previous = $last->entry_hash ?? self::GenesisHash;
            $occurredAt = now()->utc()->startOfSecond();

            $entry = new SignatureAuditLog([
                'tenant_id' => $envelope->tenant_id,
                'envelope_id' => $envelope->id,
                'recipient_id' => $recipient?->id,
                'sequence' => $sequence,
                'event' => $event->value,
                'actor_type' => $actor['type'] ?? 'system',
                'actor_id' => $actor['id'] ?? null,
                'ip_address' => $actor['ip'] ?? null,
                'user_agent' => isset($actor['user_agent']) ? mb_substr((string) $actor['user_agent'], 0, 500) : null,
                'metadata' => $metadata === [] ? null : $metadata,
                'document_sha256' => $documentSha256,
                'receipt' => $receipt,
                'timestamp_token' => $timestampToken,
                'previous_hash' => $previous,
                'occurred_at' => $occurredAt,
            ]);
            $entry->entry_hash = self::hashOf($entry);
            $entry->save();

            return $entry;
        });
    }

    /**
     * Recomputes the chain of an envelope. True when every entry carries the hash of its content and links to the previous
     * one, and the sequence has no gap.
     */
    public function verifyChain(SignatureEnvelope $envelope): bool
    {
        $previous = self::GenesisHash;
        $expected = 1;

        foreach (SignatureAuditLog::query()->where('envelope_id', $envelope->id)->orderBy('sequence')->cursor() as $entry) {
            if ($entry->sequence !== $expected || $entry->previous_hash !== $previous || ! hash_equals($entry->entry_hash, self::hashOf($entry))) {
                return false;
            }

            $previous = $entry->entry_hash;
            $expected++;
        }

        return true;
    }

    /**
     * Hash of the content of an entry, in a canonical form (sorted metadata, UTC time to the second).
     */
    public static function hashOf(SignatureAuditLog $entry): string
    {
        $metadata = $entry->metadata ?? [];
        self::sortRecursively($metadata);

        return hash('sha256', implode('|', [
            $entry->previous_hash,
            $entry->envelope_id,
            (string) $entry->recipient_id,
            (string) $entry->sequence,
            $entry->event,
            $entry->actor_type,
            (string) $entry->actor_id,
            (string) $entry->ip_address,
            (string) $entry->user_agent,
            (string) $entry->document_sha256,
            (string) $entry->receipt,
            (string) $entry->timestamp_token,
            $entry->occurred_at->copy()->utc()->format('Y-m-d\TH:i:s'),
            json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '[]',
        ]));
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function sortRecursively(array &$value): void
    {
        ksort($value);

        foreach ($value as &$item) {
            if (is_array($item)) {
                self::sortRecursively($item);
            }
        }
    }
}
