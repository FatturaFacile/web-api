<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Jobs;

use Enterprise\AuditLedger\Models\AuditExport;
use Enterprise\AuditLedger\Services\AuditPackageBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds the signed export package of a ledger export request.
 */
final class ExportAuditPackageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $exportId) {}

    public function handle(AuditPackageBuilder $builder): void
    {
        $export = AuditExport::query()->find($this->exportId);

        if (! $export instanceof AuditExport || $export->status !== AuditExport::STATUS_QUEUED) {
            return;
        }

        $export->forceFill(['status' => AuditExport::STATUS_RUNNING])->save();

        try {
            $builder->build($export);
        } catch (Throwable $e) {
            report($e);
            $export->forceFill(['status' => AuditExport::STATUS_FAILED, 'error' => substr($e->getMessage(), 0, 1000)])->save();
        }
    }
}
