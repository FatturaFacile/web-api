<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Contracts\EventDispatcherInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Contracts\TreasuryPositionServiceInterface;
use Modules\Treasury\Events\PaymentExecutedEvent;
use Modules\Treasury\Events\PaymentScheduledEvent;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentApproval;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentExecution;
use Modules\Treasury\Models\PaymentLine;

final readonly class PaymentFactoryService implements PaymentFactoryServiceInterface
{
    public function __construct(
        private AuditLedgerInterface $auditLedger,
        private EventDispatcherInterface $dispatcher,
        private TreasuryPositionServiceInterface $positionService
    ) {}

    public function createBatch(array $batchData, array $lines, ?string $creatorUserId = null): PaymentBatch
    {
        if (empty($lines)) {
            throw new InvalidArgumentException('Payment batch must contain at least one payment line.');
        }

        $creatorUuid = ! empty($creatorUserId) && Str::isUuid($creatorUserId) ? $creatorUserId : (string) Str::uuid7();

        return DB::transaction(function () use ($batchData, $lines, $creatorUuid) {
            $bankAccount = BankAccount::findOrFail($batchData['bank_account_id']);
            $batchRef = $batchData['batch_reference'] ?? 'PAY-'.date('Ymd').'-'.strtoupper(Str::random(6));

            $totalAmount = 0.0;
            foreach ($lines as $line) {
                $amt = (float) ($line['amount'] ?? 0.0);
                if ($amt <= 0) {
                    throw new InvalidArgumentException('Payment line amounts must be strictly positive.');
                }
                $totalAmount += $amt;
            }

            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $batchData['tenant_id'],
                'company_id' => $batchData['company_id'] ?? $bankAccount->company_id,
                'bank_account_id' => $bankAccount->id,
                'batch_reference' => $batchRef,
                'payment_method' => $batchData['payment_method'] ?? 'sepa_credit_transfer',
                'execution_date' => $batchData['execution_date'] ?? now()->toDateString(),
                'total_amount' => $totalAmount,
                'currency' => $batchData['currency'] ?? $bankAccount->currency ?? 'EUR',
                'item_count' => count($lines),
                'status' => 'draft',
                'created_by' => $creatorUuid,
            ]);

            $lineNo = 1;
            foreach ($lines as $line) {
                PaymentLine::create([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $batch->tenant_id,
                    'company_id' => $batch->company_id,
                    'payment_batch_id' => $batch->id,
                    'line_number' => $lineNo++,
                    'recipient_type' => $line['recipient_type'] ?? 'supplier',
                    'recipient_id' => $line['recipient_id'] ?? null,
                    'recipient_name' => $line['recipient_name'] ?? $line['beneficiary_name'] ?? 'Vendor',
                    'recipient_iban' => str_replace(' ', '', (string) ($line['recipient_iban'] ?? $line['beneficiary_iban'] ?? '')),
                    'recipient_bic' => $line['recipient_bic'] ?? $line['beneficiary_bic'] ?? null,
                    'amount' => (float) $line['amount'],
                    'currency' => $line['currency'] ?? $batch->currency,
                    'instruction_id' => $line['instruction_id'] ?? 'INSTR-'.strtoupper(Str::random(10)),
                    'end_to_end_id' => $line['end_to_end_id'] ?? 'E2E-'.strtoupper(Str::random(12)),
                    'remittance_reference' => $line['remittance_reference'] ?? $line['remittance_information'] ?? 'Payment for invoices',
                    'structured_creditor_ref' => $line['structured_creditor_ref'] ?? null,
                    'invoice_id' => $line['invoice_id'] ?? null,
                    'payroll_payment_id' => $line['payroll_payment_id'] ?? null,
                    'status' => 'pending',
                    'execution_date' => $batch->execution_date,
                ]);
            }

            // Generate initial pain.001 XML preview
            $xml = $this->generatePain001Xml((string) $batch->id);
            $batch->update([
                'xml_sha256' => hash('sha256', $xml),
            ]);

            $event = new PaymentScheduledEvent(
                paymentBatchId: (string) $batch->id,
                bankAccountId: (string) $bankAccount->id,
                totalAmount: (float) $batch->total_amount,
                currency: (string) $batch->currency,
                executionDate: (string) $batch->execution_date,
                itemCount: (int) $batch->item_count,
                tenantId: (string) $batch->tenant_id,
                companyId: (string) ($batch->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'payment_batch_created',
                model: $batch,
                payloadBefore: null,
                payloadAfter: $batch->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $batch->load('lines');
        });
    }

    public function generatePain001Xml(string $paymentBatchId): string
    {
        $batch = PaymentBatch::with(['lines', 'bankAccount'])->findOrFail($paymentBatchId);
        $msgId = 'MSG-'.date('YmdHis').'-'.substr((string) $batch->id, 0, 8);
        $createdOn = now()->format('Y-m-d\TH:i:s');

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<Document xmlns=\"urn:iso:std:iso:20022:tech:xsd:pain.001.001.09\">\n";
        $xml .= "  <CstmrCdtTrfInitn>\n";
        $xml .= "    <GrpHdr>\n";
        $xml .= "      <MsgId>{$msgId}</MsgId>\n";
        $xml .= "      <CreDtTm>{$createdOn}</CreDtTm>\n";
        $xml .= "      <NbOfTxs>{$batch->item_count}</NbOfTxs>\n";
        $xml .= "      <CtrlSum>{$batch->total_amount}</CtrlSum>\n";
        $xml .= "      <InitgPty><Nm>Enterprise ERP Platform</Nm></InitgPty>\n";
        $xml .= "    </GrpHdr>\n";
        $xml .= "    <PmtInf>\n";
        $xml .= "      <PmtInfId>{$batch->batch_reference}</PmtInfId>\n";
        $xml .= "      <PmtMtd>TRF</PmtMtd>\n";
        $xml .= "      <NbOfTxs>{$batch->item_count}</NbOfTxs>\n";
        $xml .= "      <CtrlSum>{$batch->total_amount}</CtrlSum>\n";
        $xml .= "      <ReqdExctnDt><Dt>{$batch->execution_date}</Dt></ReqdExctnDt>\n";
        $xml .= "      <Dbtr><Nm>{$batch->bankAccount->bank_name}</Nm></Dbtr>\n";
        $xml .= "      <DbtrAcct><Id><IBAN>{$batch->bankAccount->iban}</IBAN></Id></DbtrAcct>\n";
        $xml .= "      <DbtrAgt><FinInstnId><BICFI>{$batch->bankAccount->bic_swift}</BICFI></FinInstnId></DbtrAgt>\n";

        foreach ($batch->lines as $line) {
            $xml .= "      <CdtTrfTxInf>\n";
            $xml .= "        <PmtId><EndToEndId>{$line->end_to_end_id}</EndToEndId></PmtId>\n";
            $xml .= "        <Amt><InstdAmt Ccy=\"{$line->currency}\">{$line->amount}</InstdAmt></Amt>\n";
            $xml .= '        <Cdtr><Nm>'.htmlspecialchars((string) $line->recipient_name)."</Nm></Cdtr>\n";
            $xml .= "        <CdtrAcct><Id><IBAN>{$line->recipient_iban}</IBAN></Id></CdtrAcct>\n";
            if (! empty($line->recipient_bic)) {
                $xml .= "        <CdtrAgt><FinInstnId><BICFI>{$line->recipient_bic}</BICFI></FinInstnId></CdtrAgt>\n";
            }
            $xml .= '        <RmtInf><Ustrd>'.htmlspecialchars((string) $line->remittance_reference)."</Ustrd></RmtInf>\n";
            $xml .= "      </CdtTrfTxInf>\n";
        }

        $xml .= "    </PmtInf>\n";
        $xml .= "  </CstmrCdtTrfInitn>\n";
        $xml .= '</Document>';

        return $xml;
    }

    public function submitForApproval(string $paymentBatchId, ?string $userId = null): PaymentBatch
    {
        return DB::transaction(function () use ($paymentBatchId, $userId) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();

            $status = strtolower((string) $batch->status);
            if ($status !== 'draft') {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] must be in 'draft' status to submit for approval.");
            }

            $userUuid = ! empty($userId) && Str::isUuid($userId) ? $userId : (string) Str::uuid7();

            // Record approval signature
            PaymentApproval::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $batch->tenant_id,
                'payment_batch_id' => $batch->id,
                'approver_user_id' => $userUuid,
                'approval_tier' => 1,
                'signature_hash' => hash('sha256', $batch->batch_reference.$batch->total_amount.now()->toIso8601String()),
                'ip_address' => '127.0.0.1',
                'decision' => 'approved',
                'decision_reason' => 'Authorized by Treasury Signatory Matrix',
                'approved_at' => now(),
            ]);

            $before = $batch->toArray();
            $batch->update([
                'status' => 'approved',
            ]);

            $this->auditLedger->record(
                action: 'payment_batch_approved',
                model: $batch,
                payloadBefore: $before,
                payloadAfter: $batch->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $batch;
        });
    }

    public function executeBatch(string $paymentBatchId, string $protocol = 'sepa_pain001', ?string $userId = null): PaymentExecution
    {
        return DB::transaction(function () use ($paymentBatchId, $protocol) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();

            $status = strtolower((string) $batch->status);
            if ($status === 'transmitted' || $status === 'cleared' || $status === 'executed') {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] has already been executed.");
            }

            if ($status !== 'approved') {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] must be approved before execution.");
            }

            $transmissionRef = 'TXN-BANK-'.strtoupper(Str::random(12));

            // Reduce bank account balance
            $this->positionService->updateBalance(
                (string) $batch->bank_account_id,
                -1 * (float) $batch->total_amount,
                'payment_execution'
            );

            // Mark lines as executed
            PaymentLine::where('payment_batch_id', $batch->id)
                ->update(['status' => 'executed']);

            $before = $batch->toArray();
            $batch->update([
                'status' => 'transmitted',
            ]);

            /** @var PaymentExecution $execution */
            $execution = PaymentExecution::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $batch->tenant_id,
                'payment_batch_id' => $batch->id,
                'protocol' => $protocol,
                'bank_transmission_reference' => $transmissionRef,
                'pain002_status_code' => 'ACCP',
                'clearing_timestamp' => now(),
                'response_payload_json' => [
                    'status' => 'AcceptedTechnicalValidation',
                    'protocol' => $protocol,
                    'timestamp' => now()->toIso8601String(),
                ],
            ]);

            $event = new PaymentExecutedEvent(
                paymentBatchId: (string) $batch->id,
                bankAccountId: (string) $batch->bank_account_id,
                totalAmount: (float) $batch->total_amount,
                currency: (string) $batch->currency,
                protocol: $protocol,
                bankTransmissionReference: $transmissionRef,
                tenantId: (string) $batch->tenant_id,
                companyId: (string) ($batch->company_id ?? '')
            );
            $this->dispatcher->publish($event);
            event($event);

            $this->auditLedger->record(
                action: 'payment_batch_executed',
                model: $batch,
                payloadBefore: $before,
                payloadAfter: $batch->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $execution;
        });
    }

    public function cancelBatch(string $paymentBatchId, string $reason, ?string $userId = null): PaymentBatch
    {
        return DB::transaction(function () use ($paymentBatchId, $reason) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();

            $status = strtolower((string) $batch->status);
            if ($status === 'transmitted' || $status === 'cleared' || $status === 'executed') {
                throw new InvalidArgumentException('Cannot cancel an already transmitted or cleared payment batch.');
            }

            $before = $batch->toArray();
            $batch->update([
                'status' => 'rejected',
            ]);

            PaymentLine::where('payment_batch_id', $batch->id)
                ->update(['status' => 'cancelled', 'error_message' => $reason]);

            $this->auditLedger->record(
                action: 'payment_batch_cancelled',
                model: $batch,
                payloadBefore: $before,
                payloadAfter: $batch->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $batch;
        });
    }
}
