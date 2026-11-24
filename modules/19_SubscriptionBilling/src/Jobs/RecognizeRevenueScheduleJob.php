<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;

final class RecognizeRevenueScheduleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public ?string $companyId = null,
        public ?string $periodDate = null
    ) {}

    public function handle(RevenueRecognitionServiceInterface $revRecService): void
    {
        $dto = new RecognizeRevenueDTO(
            tenantId: $this->tenantId,
            companyId: $this->companyId,
            periodDate: $this->periodDate,
            executedBy: null
        );

        $revRecService->recognizeRevenue($dto);
    }
}
