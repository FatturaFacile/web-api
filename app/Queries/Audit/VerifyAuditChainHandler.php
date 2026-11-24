<?php

declare(strict_types=1);

namespace App\Queries\Audit;

use App\Domain\Audit\DTOs\AuditVerificationCertificate;
use App\Models\AuditLog;
use App\Services\Audit\AuditChainCryptoService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Walks the ledger of a tenant and proves it intact: no gap in the sequence, every previous_hash equal to the hash of the
 * record before, every hash recomputed from the stored fields and every HMAC signature valid. The first failure ends the
 * walk and names the sequence number where the chain breaks.
 */
final class VerifyAuditChainHandler
{
    private const CHUNK = 500;

    public function __construct(private readonly AuditChainCryptoService $crypto) {}

    public function handle(VerifyAuditChainQuery $query): AuditVerificationCertificate
    {
        $from = $query->fromSequence !== null ? max(1, $query->fromSequence) : null;
        $expectedPrevious = AuditChainCryptoService::GENESIS_HASH;
        $expectedSequence = 1;

        if ($from !== null && $from > 1) {
            $predecessor = $this->scope($query->tenantId)->where('sequence_number', $from - 1)->first();

            if ($predecessor === null) {
                return $this->certificate($query, false, 0, null, null, [], $from, 'The record before the requested range is missing.');
            }

            $expectedPrevious = $predecessor->current_hash;
            $expectedSequence = $from;
        } elseif ($from === 1) {
            $expectedSequence = 1;
        }

        $cursor = $expectedSequence - 1;
        $hashes = [];
        $first = null;
        $last = null;

        do {
            $rows = $this->scope($query->tenantId)
                ->where('sequence_number', '>', $cursor)
                ->when($query->toSequence !== null, fn (Builder $q) => $q->where('sequence_number', '<=', $query->toSequence))
                ->orderBy('sequence_number')
                ->limit(self::CHUNK)
                ->get();

            foreach ($rows as $row) {
                $failure = $this->check($row, $expectedSequence, $expectedPrevious);

                if ($failure !== null) {
                    return $this->certificate($query, false, count($hashes), $first, $last, $hashes, $row->sequence_number, $failure);
                }

                $first ??= $row->sequence_number;
                $last = $row->sequence_number;
                $hashes[] = $row->current_hash;
                $expectedPrevious = $row->current_hash;
                $expectedSequence++;
                $cursor = $row->sequence_number;
            }
        } while ($rows->count() === self::CHUNK);

        return $this->certificate($query, true, count($hashes), $first, $last, $hashes, null, null);
    }

    private function check(AuditLog $row, int $expectedSequence, string $expectedPrevious): ?string
    {
        if ($row->sequence_number !== $expectedSequence) {
            return "Sequence gap: expected #{$expectedSequence}, found #{$row->sequence_number} (a record was deleted or reordered).";
        }

        if (! hash_equals($expectedPrevious, $row->previous_hash)) {
            return 'previous_hash does not match the hash of the preceding record.';
        }

        $recomputed = $this->crypto->computeHash($row->previous_hash, $this->crypto->hashableFields($row));

        if (! hash_equals($recomputed, $row->current_hash)) {
            return 'current_hash does not match the recomputed hash: the record was altered.';
        }

        if (! $this->crypto->verifySignature($row->current_hash, $row->hmac_signature)) {
            return 'hmac_signature is invalid.';
        }

        return null;
    }

    /**
     * @return Builder<AuditLog>
     */
    private function scope(?string $tenantId): Builder
    {
        return AuditLog::query()->when(
            $tenantId === null,
            fn (Builder $q) => $q->whereNull('tenant_id'),
            fn (Builder $q) => $q->where('tenant_id', $tenantId),
        );
    }

    /**
     * @param  list<string>  $hashes
     */
    private function certificate(VerifyAuditChainQuery $query, bool $valid, int $checked, ?int $first, ?int $last, array $hashes, ?int $brokenAt, ?string $reason): AuditVerificationCertificate
    {
        $certificate = new AuditVerificationCertificate(
            isValid: $valid,
            checkedRecords: $checked,
            firstSequence: $first,
            lastSequence: $last,
            merkleRoot: $this->crypto->merkleRoot($hashes),
            brokenAtSequence: $brokenAt,
            failureReason: $reason,
            verifiedAt: Carbon::now('UTC')->format('Y-m-d\TH:i:s.u\Z'),
            tenantId: $query->tenantId,
        );

        return $certificate->withSignature($this->crypto->sign($this->crypto->canonicalJson($certificate->payload())));
    }
}
