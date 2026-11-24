<?php

declare(strict_types=1);

namespace App\Services\Privacy\Governance\Commands;

use App\Jobs\Privacy\CompileDsarPortablePackageJob;
use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Services\Privacy\Governance\DsarService;
use RuntimeException;

/**
 * Queues the compilation of the portability package; the request exposes package_status for real-time polling.
 */
final readonly class GenerateDsarPortablePackageHandler
{
    public function __construct(private DsarService $dsars) {}

    public function handle(GenerateDsarPortablePackageCommand $command): PrivacySubjectRequestDsar
    {
        $dsar = $this->dsars->find($command->tenantId, $command->dsarId);
        if ($dsar->identity_verified_at === null) {
            throw new RuntimeException('The identity of the requester must be verified before the data are exported.');
        }

        $dsar->update(['package_status' => 'queued']);
        CompileDsarPortablePackageJob::dispatch($command->tenantId, $command->dsarId);

        return $dsar->refresh();
    }
}
