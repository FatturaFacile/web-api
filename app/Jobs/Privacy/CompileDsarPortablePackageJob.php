<?php

declare(strict_types=1);

namespace App\Jobs\Privacy;

use App\Services\Privacy\Governance\DsarPortablePackageService;
use App\Services\Privacy\Governance\DsarService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CompileDsarPortablePackageJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public function __construct(public readonly string $tenantId, public readonly string $dsarId) {}

    public function handle(DsarService $dsars, DsarPortablePackageService $packages): void
    {
        $packages->compile($dsars->find($this->tenantId, $this->dsarId));
    }
}
