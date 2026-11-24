<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Actions;

use Modules\SubscriptionBilling\Contracts\RevenueRecognitionServiceInterface;
use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;

final readonly class RecognizeRevenueAction
{
    public function __construct(
        private RevenueRecognitionServiceInterface $revRecService
    ) {}

    public function execute(RecognizeRevenueDTO $dto): array
    {
        return $this->revRecService->recognizeRevenue($dto);
    }
}
