<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance;

use App\Models\Privacy\ConsentLedgerEntry;
use Carbon\CarbonImmutable;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Appends to the tamper-evident consent ledger. The subject is stored as a salted hash of the identifier (e-mail), so
 * the ledger proves consent without being a second copy of the contact data and survives an erasure.
 */
final class ConsentLedgerService
{
    public static function identifierHash(string $identifier): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($identifier)), (string) config('app.key'));
    }

    /**
     * @param  array{subject_type: string, subject_entity_id?: string|null, subject_identifier: string, purpose_code: string, consent_granted: bool, policy_version: string, proof_channel: string, ip_address?: string|null, user_agent?: string|null, captured_at?: string|null}  $data
     */
    public function record(string $tenantId, array $data): ConsentLedgerEntry
    {
        return DB::transaction(function () use ($tenantId, $data): ConsentLedgerEntry {
            $last = ConsentLedgerEntry::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenantId)->orderByDesc('sequence')->lockForUpdate()->first();

            $content = [
                'tenant_id' => $tenantId,
                'sequence' => ($last->sequence ?? 0) + 1,
                'subject_type' => $data['subject_type'],
                'subject_entity_id' => $data['subject_entity_id'] ?? null,
                'subject_identifier_hash' => self::identifierHash($data['subject_identifier']),
                'purpose_code' => $data['purpose_code'],
                'consent_granted' => $data['consent_granted'],
                'policy_version' => $data['policy_version'],
                'captured_at' => CarbonImmutable::parse($data['captured_at'] ?? now())->toIso8601String(),
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => isset($data['user_agent']) ? mb_substr($data['user_agent'], 0, 500) : null,
                'proof_channel' => $data['proof_channel'],
            ];
            $previous = $last->entry_hash ?? ConsentLedgerEntry::GenesisHash;

            return ConsentLedgerEntry::withoutGlobalScope(TenantScope::class)->create($content + [
                'previous_entry_hash' => $previous,
                'entry_hash' => ConsentLedgerEntry::computeHash($content, $previous),
            ]);
        });
    }

    /**
     * Recomputes the chain of the tenant. Returns the sequence of the first broken entry, or null when intact.
     */
    public function firstBrokenSequence(string $tenantId): ?int
    {
        $previous = ConsentLedgerEntry::GenesisHash;
        $broken = null;

        ConsentLedgerEntry::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->orderBy('sequence')
            ->chunk(500, function ($entries) use (&$previous, &$broken): bool {
                foreach ($entries as $entry) {
                    $content = [
                        'tenant_id' => $entry->tenant_id,
                        'sequence' => $entry->sequence,
                        'subject_type' => $entry->subject_type->value,
                        'subject_entity_id' => $entry->subject_entity_id,
                        'subject_identifier_hash' => $entry->subject_identifier_hash,
                        'purpose_code' => $entry->purpose_code,
                        'consent_granted' => $entry->consent_granted,
                        'policy_version' => $entry->policy_version,
                        'captured_at' => $entry->captured_at->toIso8601String(),
                        'ip_address' => $entry->ip_address,
                        'user_agent' => $entry->user_agent,
                        'proof_channel' => $entry->proof_channel->value,
                    ];
                    if ($entry->previous_entry_hash !== $previous || $entry->entry_hash !== ConsentLedgerEntry::computeHash($content, $previous)) {
                        $broken = $entry->sequence;

                        return false;
                    }

                    $previous = $entry->entry_hash;
                }

                return true;
            });

        return $broken;
    }
}
