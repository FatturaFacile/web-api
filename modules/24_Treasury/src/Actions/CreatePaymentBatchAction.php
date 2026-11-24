<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\PaymentBatch;

final readonly class CreatePaymentBatchAction
{
    public function __construct(
        private PaymentFactoryServiceInterface $paymentFactory
    ) {}

    /**
     * @param  array<string, mixed>  $batchData
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function execute(array $batchData, array $lines, ?string $userId = null): PaymentBatch
    {
        return $this->paymentFactory->createBatch($batchData, $lines, $userId);
    }
}
