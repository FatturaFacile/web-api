<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Infrastructure\Services;

use Modules\DigitalPreservation\Domain\Enums\AuditAction;
use Modules\DigitalPreservation\Domain\Events\IntegrityVerificationFailed;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatch;
use Modules\DigitalPreservation\Infrastructure\Models\PreservationBatchItem;

/**
 * Re-hashes the stored files and checks the Merkle root, the PdV hash, the link to the previous batch, the time stamp and
 * the audit log chain. A mismatch raises an alert in the audit log and an event.
 */
final readonly class PreservationIntegrityVerifier
{
    private const string ZeroHash = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(
        private PreservationVault $vault,
        private MerkleTreeHasher $merkle,
        private Rfc3161TimestampService $timestamps,
        private PreservationAuditLogger $audit,
    ) {}

    /**
     * @return array{ok: bool, checked_files: int, failures: list<array{type: string, reference: string, expected: ?string, actual: ?string}>, chain: array{ok: bool}, timestamp: array{valid: ?bool}, audit_chain: array{ok: bool}, verified_at: string}
     */
    public function verifyBatch(PreservationBatch $batch, bool $record = true): array
    {
        $failures = [];
        $leaves = [];
        $checked = 0;

        foreach ($batch->items()->get() as $item) {
            $checked++;
            $result = $this->verifyItem($item);
            $leaves[] = $item->sha256_hash;

            if (! $result['ok']) {
                $failures[] = ['type' => $result['actual'] === null ? 'file_missing' : 'file_hash_mismatch', 'reference' => $item->archive_path, 'expected' => $result['expected'], 'actual' => $result['actual']];
            }
        }

        $chainOk = true;
        $timestampValid = null;

        if ($batch->status->isFrozen()) {
            if ($batch->merkle_root_sha256 !== null && ! $this->merkle->verify($leaves, $batch->merkle_root_sha256)) {
                $failures[] = ['type' => 'merkle_root_mismatch', 'reference' => $batch->batch_number, 'expected' => $batch->merkle_root_sha256, 'actual' => $this->merkle->root($leaves)];
            }

            $pdv = $this->vault->get($batch->pdv_xml_path);
            $pdvHash = $pdv === null ? null : $this->merkle->leaf($pdv);

            if ($pdvHash === null || $batch->pdv_sha256 === null || ! hash_equals($batch->pdv_sha256, $pdvHash)) {
                $failures[] = ['type' => 'pdv_hash_mismatch', 'reference' => (string) $batch->pdv_xml_path, 'expected' => $batch->pdv_sha256, 'actual' => $pdvHash];
            }

            if ($batch->pda_archive_path !== null) {
                $pda = $this->vault->get($batch->pda_archive_path);
                $pdaHash = $pda === null ? null : $this->merkle->leaf($pda);

                if ($pdaHash === null || $batch->pda_sha256 === null || ! hash_equals($batch->pda_sha256, $pdaHash)) {
                    $failures[] = ['type' => 'pda_hash_mismatch', 'reference' => $batch->pda_archive_path, 'expected' => $batch->pda_sha256, 'actual' => $pdaHash];
                }
            }

            $link = $batch->previous_batch_sha256 ?? self::ZeroHash;

            if ($link !== self::ZeroHash && ! PreservationBatch::where('id', '!=', $batch->id)->where('pdv_sha256', $link)->exists()) {
                $chainOk = false;
                $failures[] = ['type' => 'chain_link_mismatch', 'reference' => $batch->batch_number, 'expected' => $link, 'actual' => null];
            }

            if ($batch->rfc3161_token_path !== null) {
                $token = $this->vault->get($batch->rfc3161_token_path);
                $timestampValid = $token !== null && $batch->pdv_sha256 !== null && $this->timestamps->verify($token, $batch->pdv_sha256);

                if (! $timestampValid) {
                    $failures[] = ['type' => 'timestamp_invalid', 'reference' => $batch->rfc3161_token_path, 'expected' => $batch->pdv_sha256, 'actual' => null];
                }
            }
        }

        $auditChain = $this->audit->verifyChain($batch->tenant_id);

        if (! $auditChain['ok']) {
            $failures[] = ['type' => 'audit_chain_broken', 'reference' => 'sequence '.$auditChain['broken_at'], 'expected' => null, 'actual' => null];
        }

        $report = [
            'ok' => $failures === [],
            'checked_files' => $checked,
            'failures' => $failures,
            'chain' => ['ok' => $chainOk],
            'timestamp' => ['valid' => $timestampValid],
            'audit_chain' => ['ok' => $auditChain['ok']],
            'verified_at' => now()->toIso8601String(),
        ];

        if ($record) {
            $this->audit->record(
                $failures === [] ? AuditAction::IntegrityVerified : AuditAction::IntegrityAlert,
                $batch, null, ['checked_files' => $checked, 'failures' => count($failures), 'failure_types' => array_values(array_unique(array_column($failures, 'type')))],
                $batch->pdv_sha256,
            );

            if ($failures !== []) {
                event(new IntegrityVerificationFailed($batch->tenant_id, $batch->id, $failures));
            }
        }

        return $report;
    }

    /**
     * Live hash check of one preserved file.
     *
     * @return array{ok: bool, expected: string, actual: ?string}
     */
    public function verifyItem(PreservationBatchItem $item): array
    {
        $content = $this->vault->get($item->archive_path);
        $actual = $content === null ? null : $this->merkle->leaf($content);

        return ['ok' => $actual !== null && hash_equals($item->sha256_hash, $actual), 'expected' => $item->sha256_hash, 'actual' => $actual];
    }
}
