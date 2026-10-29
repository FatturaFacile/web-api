<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Contracts;

use Modules\SubscriptionBilling\DTOs\RecognizeRevenueDTO;
use Modules\SubscriptionBilling\Models\RevenueContract;
use Modules\SubscriptionBilling\Models\Subscription;

interface RevenueRecognitionServiceInterface
{
    public function createRevenueContract(Subscription $subscription): RevenueContract;

    public function allocateTransactionPrice(RevenueContract $contract): void;

    public function generateRevenueSchedules(RevenueContract $contract): array;

    public function recognizeRevenue(RecognizeRevenueDTO $dto): array;
}
