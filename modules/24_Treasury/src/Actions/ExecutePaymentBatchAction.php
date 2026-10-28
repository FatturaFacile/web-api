<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\PaymentExecution;

final readonly class ExecutePaymentBatchAction
{
    public function __construct(
        private PaymentFactoryServiceInterface $paymentFactory
    ) {}

    public function execute(string $paymentBatchId, string $protocol = 'sepa_pain001', ?string $userId = null): PaymentExecution
    {
        return $this->paymentFactory->executeBatch($paymentBatchId, $protocol, $userId);
    }
}
