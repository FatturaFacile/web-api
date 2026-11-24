<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Queries\Audit\VerifyAuditChainHandler;
use App\Queries\Audit\VerifyAuditChainQuery;
use App\Services\Audit\AuditWormArchiver;
use Illuminate\Console\Command;

final class AuditArchiveWormCommand extends Command
{
    protected $signature = 'audit:archive-worm {--tenant= : Only this tenant id} {--verify : Verify the chain and the sealed batches afterwards}';

    protected $description = 'Seal the unarchived audit trail records into signed JSONL batches on the WORM storage';

    public function handle(AuditWormArchiver $archiver, VerifyAuditChainHandler $verifier): int
    {
        $tenant = $this->option('tenant');
        $targets = is_string($tenant) && $tenant !== ''
            ? [$tenant]
            : AuditLog::query()->distinct()->pluck('tenant_id')->all();

        $failed = false;
        $sealed = 0;

        foreach ($targets as $tenantId) {
            $tenantId = $tenantId === null ? null : (string) $tenantId;
            $label = $tenantId ?? 'system';

            while (($batch = $archiver->seal($tenantId)) !== null) {
                $sealed++;
                $this->info("[{$label}] sealed #{$batch->from_sequence}-#{$batch->to_sequence} ({$batch->record_count} records, merkle {$batch->merkle_root_hash}) -> {$batch->storage_path}");

                if ($this->option('verify')) {
                    $check = $archiver->verifyBatch($batch);
                    $failed = $failed || ! $check['valid'];
                    $this->line("[{$label}] batch {$batch->id}: ".($check['valid'] ? 'archive verified' : 'ARCHIVE INVALID: '.$check['reason']));
                }
            }

            if ($this->option('verify')) {
                $certificate = $verifier->handle(new VerifyAuditChainQuery($tenantId));
                $failed = $failed || ! $certificate->isValid;
                $this->line("[{$label}] chain: ".($certificate->isValid
                    ? "valid, {$certificate->checkedRecords} records, merkle root {$certificate->merkleRoot}"
                    : "BROKEN at #{$certificate->brokenAtSequence}: {$certificate->failureReason}"));
            }
        }

        if ($sealed === 0) {
            $this->info('Nothing to seal: every record is already archived.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
