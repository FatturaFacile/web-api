<?php

declare(strict_types=1);

namespace Modules\HumanResources\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\HumanResources\Contracts\TrainingAndCertificationServiceInterface;

final class ScanExpiringCertificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;

    public function __construct(
        public readonly string $tenantId,
        public readonly int $withinDays = 30
    ) {}

    public function handle(TrainingAndCertificationServiceInterface $trainingService): void
    {
        $trainingService->scanExpiringCertifications($this->tenantId, $this->withinDays);
    }
}
