<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentExecution;

interface PaymentFactoryServiceInterface
{
    /**
     * Create a payment batch from scheduled liabilities or vendor invoices.
     *
     * @param  array<string, mixed>  $batchData
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createBatch(array $batchData, array $lines, ?string $creatorUserId = null): PaymentBatch;

    /**
     * Generate ISO 20022 pain.001 XML message for SEPA / Swiss QR clearing.
     */
    public function generatePain001Xml(string $paymentBatchId): string;

    /**
     * Submit batch for approval through Module 21 Workflow Engine V2.
     */
    public function submitForApproval(string $paymentBatchId, ?string $userId = null): PaymentBatch;

    /**
     * Execute approved payment batch, dispatching transmission to bank host.
     */
    public function executeBatch(string $paymentBatchId, string $protocol = 'sepa_pain001', ?string $userId = null): PaymentExecution;

    /**
     * Cancel an unexecuted payment batch and release liabilities.
     */
    public function cancelBatch(string $paymentBatchId, string $reason, ?string $userId = null): PaymentBatch;
}
