<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DocumentTrust\Models\PreservationDisposal;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Services\PreservationPackageService;

/**
 * Periodic integrity check: recomputes the hashes of every closed package of the tenant and records the result of each.
 * A failed check stays in the verification history of the package, which the API and the audit ledger expose.
 */
final class VerifyPreservationPackagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Inject the tenant.
     */
    public function __construct(public readonly string $tenantId)
    {
        $this->afterCommit();
    }

    /**
     * Verify each closed package.
     *
     * @return int number of packages that failed
     */
    public function handle(PreservationPackageService $packages): int
    {
        $failed = 0;

        PreservationPackage::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('status', PreservationPackage::Closed)
            ->whereNotIn('id', PreservationDisposal::withoutGlobalScopes()->select('package_id'))
            ->orderBy('id')
            ->each(function (PreservationPackage $package) use ($packages, &$failed): void {
                if ($packages->verify($package, 'scheduled')->result === 'failed') {
                    $failed++;
                }
            });

        return $failed;
    }
}
