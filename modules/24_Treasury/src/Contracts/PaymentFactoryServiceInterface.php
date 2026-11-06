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
     * Submit a draft batch for approval. It only waits: approval comes from a signatory or Module 21 Workflow Engine V2.
     */
    public function submitForApproval(string $paymentBatchId, ?string $userId = null): PaymentBatch;

    /**
     * Record the approval of an identified signatory other than the creator of the batch.
     */
    public function approveBatch(string $paymentBatchId, string $approverUserId, ?string $ipAddress): PaymentBatch;

    /**
     * Create a batch and its file in one step, for the legacy purchase flow.
     *
     * @param  array<string, mixed>  $batchData
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createExportedBatch(array $batchData, array $lines, string $userId, ?string $ipAddress): PaymentBatch;

    /**
     * Generate and freeze the pain.001 file of an approved batch. Nothing has been paid yet: the file still has to
     * reach the bank, and neither the balance nor the ledger change.
     */
    public function exportBatch(string $paymentBatchId, string $protocol = 'sepa_pain001', ?string $userId = null): PaymentExecution;

    /**
     * Manual confirmation that the bank executed the lines still pending.
     */
    public function confirmBatch(string $paymentBatchId, string $bankReference, ?string $valueDate = null, ?string $userId = null): PaymentBatch;

    /**
     * Apply the outcome reported by the bank in a pain.002 status report.
     */
    public function importPain002(string $paymentBatchId, string $xml, ?string $userId = null): PaymentBatch;

    /**
     * Cancel an unexecuted payment batch and release liabilities.
     */
    public function cancelBatch(string $paymentBatchId, string $reason, ?string $userId = null): PaymentBatch;
}
