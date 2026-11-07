<?php

declare(strict_types=1);

namespace Modules\Treasury\Services;

use App\Models\PassiveInvoice;
use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use DOMXPath;
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
    private const string PAIN001_NAMESPACE = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';

    /** pain.002 statuses that mean the bank took the payment: settled, settling, accepted, accepted with change. */
    private const array PAIN002_EXECUTED = ['ACSC', 'ACSP', 'ACCP', 'ACWC'];

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

        $this->assertInvoicesCleared((string) $batchData['tenant_id'], array_filter(array_column($lines, 'invoice_id')));

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
                'format' => $batchData['format'] ?? 'sepa_pain_001_it',
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

        // Once exported, the file is the one given to the bank: it never changes.
        if ($batch->pain001_xml !== null) {
            return (string) $batch->pain001_xml;
        }

        return $this->buildPain001($batch, 'MSG-'.date('YmdHis').'-'.substr((string) $batch->id, 0, 8));
    }

    private function buildPain001(PaymentBatch $batch, string $messageId): string
    {
        $debtorName = (string) (Tenant::query()->whereKey($batch->tenant_id)->value('legal_name') ?: $batch->bankAccount->bank_name);
        $controlSum = number_format((float) $batch->total_amount, 2, '.', '');

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::PAIN001_NAMESPACE, 'Document');
        $dom->appendChild($root);
        $initiation = $this->child($dom, $root, 'CstmrCdtTrfInitn');

        $header = $this->child($dom, $initiation, 'GrpHdr');
        $this->child($dom, $header, 'MsgId', $messageId);
        $this->child($dom, $header, 'CreDtTm', now()->format('Y-m-d\TH:i:s'));
        $this->child($dom, $header, 'NbOfTxs', (string) $batch->item_count);
        $this->child($dom, $header, 'CtrlSum', $controlSum);
        $this->child($dom, $this->child($dom, $header, 'InitgPty'), 'Nm', $debtorName);

        $information = $this->child($dom, $initiation, 'PmtInf');
        $this->child($dom, $information, 'PmtInfId', (string) $batch->batch_reference);
        $this->child($dom, $information, 'PmtMtd', 'TRF');
        $this->child($dom, $information, 'NbOfTxs', (string) $batch->item_count);
        $this->child($dom, $information, 'CtrlSum', $controlSum);
        $this->child($dom, $this->child($dom, $information, 'ReqdExctnDt'), 'Dt', $batch->execution_date->toDateString());
        $this->child($dom, $this->child($dom, $information, 'Dbtr'), 'Nm', $debtorName);
        $this->child($dom, $this->child($dom, $this->child($dom, $information, 'DbtrAcct'), 'Id'), 'IBAN', (string) $batch->bankAccount->iban);
        $this->financialInstitution($dom, $this->child($dom, $information, 'DbtrAgt'), $batch->bankAccount->bic_swift, true);

        foreach ($batch->lines as $line) {
            $transaction = $this->child($dom, $information, 'CdtTrfTxInf');
            $paymentId = $this->child($dom, $transaction, 'PmtId');

            if (! empty($line->instruction_id)) {
                $this->child($dom, $paymentId, 'InstrId', (string) $line->instruction_id);
            }

            $this->child($dom, $paymentId, 'EndToEndId', (string) $line->end_to_end_id);
            $amount = $this->child($dom, $this->child($dom, $transaction, 'Amt'), 'InstdAmt', number_format((float) $line->amount, 2, '.', ''));
            $amount->setAttribute('Ccy', (string) $line->currency);

            if (! empty($line->recipient_bic)) {
                $this->financialInstitution($dom, $this->child($dom, $transaction, 'CdtrAgt'), $line->recipient_bic, false);
            }

            $this->child($dom, $this->child($dom, $transaction, 'Cdtr'), 'Nm', (string) $line->recipient_name);
            $this->child($dom, $this->child($dom, $this->child($dom, $transaction, 'CdtrAcct'), 'Id'), 'IBAN', (string) $line->recipient_iban);
            $remittance = $this->child($dom, $transaction, 'RmtInf');

            if (! empty($line->structured_creditor_ref)) {
                $this->child($dom, $this->child($dom, $this->child($dom, $remittance, 'Strd'), 'CdtrRefInf'), 'Ref', (string) $line->structured_creditor_ref);
            } else {
                $this->child($dom, $remittance, 'Ustrd', (string) $line->remittance_reference);
            }
        }

        return (string) $dom->saveXML();
    }

    private function child(DOMDocument $dom, DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $dom->createElementNS(self::PAIN001_NAMESPACE, $name);

        if ($value !== null) {
            $element->appendChild($dom->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }

    private function financialInstitution(DOMDocument $dom, DOMElement $agent, ?string $bic, bool $required): void
    {
        $institution = $this->child($dom, $agent, 'FinInstnId');

        if (! empty($bic)) {
            $this->child($dom, $institution, 'BICFI', $bic);
        } elseif ($required) {
            $this->child($dom, $this->child($dom, $institution, 'Othr'), 'Id', 'NOTPROVIDED');
        }
    }

    /**
     * A supplier invoice still waiting for approval, or rejected, cannot enter a payment file.
     *
     * @param  array<int, string>  $invoiceIds
     *
     * @throws InvalidArgumentException
     */
    private function assertInvoicesCleared(string $tenantId, array $invoiceIds): void
    {
        $blocked = PassiveInvoice::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $invoiceIds)
            ->whereIn('approval_status', PassiveInvoice::PaymentBlockingStatuses)
            ->pluck('invoice_number');

        if ($blocked->isNotEmpty()) {
            throw new InvalidArgumentException('Supplier invoices not approved for payment: '.$blocked->implode(', '));
        }
    }

    public function submitForApproval(string $paymentBatchId, ?string $userId = null): PaymentBatch
    {
        return DB::transaction(function () use ($paymentBatchId) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();

            if (strtolower((string) $batch->status) !== 'draft') {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] must be in 'draft' status to submit for approval.");
            }

            $this->assertInvoicesCleared((string) $batch->tenant_id, $batch->lines()->whereNotNull('invoice_id')->pluck('invoice_id')->all());

            $before = $batch->toArray();
            $batch->update(['status' => 'pending_approval']);

            $this->auditLedger->record(
                action: 'payment_batch_submitted',
                model: $batch,
                payloadBefore: $before,
                payloadAfter: $batch->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $batch;
        });
    }

    public function approveBatch(string $paymentBatchId, string $approverUserId, ?string $ipAddress): PaymentBatch
    {
        return DB::transaction(function () use ($paymentBatchId, $approverUserId, $ipAddress) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();

            if (strtolower((string) $batch->status) !== 'pending_approval') {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] is not waiting for approval.");
            }

            if ((string) $batch->created_by === $approverUserId) {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] must be approved by someone other than its creator.");
            }

            $this->recordApproval($batch, $approverUserId, $ipAddress, 'Approved by the signatory');

            return $batch;
        });
    }

    /**
     * The legacy purchase flow gives the file to the operator in one call: the operator is the approver on record,
     * and the signature that moves the money stays the one at the bank.
     */
    public function createExportedBatch(array $batchData, array $lines, string $userId, ?string $ipAddress): PaymentBatch
    {
        return DB::transaction(function () use ($batchData, $lines, $userId, $ipAddress) {
            $batch = $this->createBatch($batchData, $lines, $userId);
            $this->recordApproval($batch, $userId, $ipAddress, 'File requested by the operator; authorised at the bank');
            $this->exportBatch((string) $batch->id, 'sepa_pain001', $userId);

            return $batch->refresh()->load('lines');
        });
    }

    private function recordApproval(PaymentBatch $batch, string $approverUserId, ?string $ipAddress, string $reason): void
    {
        if (! Str::isUuid($approverUserId)) {
            throw new InvalidArgumentException('A payment batch can be approved only by an identified user.');
        }

        PaymentApproval::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $batch->tenant_id,
            'payment_batch_id' => $batch->id,
            'approver_user_id' => $approverUserId,
            'approval_tier' => 1,
            'signature_hash' => hash('sha256', $batch->batch_reference.$batch->total_amount.$approverUserId.now()->toIso8601String()),
            'ip_address' => $ipAddress,
            'decision' => 'approved',
            'decision_reason' => $reason,
            'approved_at' => now(),
        ]);

        $before = $batch->toArray();
        $batch->update(['status' => 'approved']);

        $this->auditLedger->record(
            action: 'payment_batch_approved',
            model: $batch,
            payloadBefore: $before,
            payloadAfter: $batch->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );
    }

    public function exportBatch(string $paymentBatchId, string $protocol = 'sepa_pain001', ?string $userId = null): PaymentExecution
    {
        return DB::transaction(function () use ($paymentBatchId, $protocol, $userId) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::with(['lines', 'bankAccount'])->where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();

            $status = strtolower((string) $batch->status);
            if (in_array($status, ['exported', 'partially_executed', 'executed'], true)) {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] has already been exported.");
            }

            $this->assertNotRiba($batch);

            if ($status !== 'approved') {
                throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] must be approved before export.");
            }

            $messageId = 'MSG-'.date('YmdHis').'-'.substr((string) $batch->id, 0, 8);
            $xml = $this->buildPain001($batch, $messageId);
            $before = $batch->toArray();

            $batch->update([
                'status' => 'exported',
                'pain001_xml' => $xml,
                'pain001_message_id' => $messageId,
                'xml_sha256' => hash('sha256', $xml),
                'exported_at' => now(),
                'exported_by' => $userId !== null && Str::isUuid($userId) ? $userId : null,
            ]);

            /** @var PaymentExecution $execution */
            $execution = PaymentExecution::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $batch->tenant_id,
                'payment_batch_id' => $batch->id,
                'protocol' => $protocol,
            ]);

            $this->auditLedger->record(
                action: 'payment_batch_exported',
                model: $batch,
                payloadBefore: $before,
                payloadAfter: $batch->toArray(),
                moduleCode: 'MODULE_24_TREASURY'
            );

            return $execution;
        });
    }

    public function confirmBatch(string $paymentBatchId, string $bankReference, ?string $valueDate = null, ?string $userId = null): PaymentBatch
    {
        return DB::transaction(function () use ($paymentBatchId, $bankReference, $valueDate, $userId) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();
            $pending = PaymentLine::where('payment_batch_id', $batch->id)->where('status', 'pending')->pluck('id')->all();

            return $this->applyBankOutcome($batch, $pending, [], $bankReference, 'MANUAL', $valueDate, ['confirmed_by' => $userId]);
        });
    }

    public function importPain002(string $paymentBatchId, string $xml, ?string $userId = null): PaymentBatch
    {
        return DB::transaction(function () use ($paymentBatchId, $xml, $userId) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();
            $report = $this->parsePain002($xml);

            if ($report['original_message_id'] !== (string) $batch->pain001_message_id) {
                throw new InvalidArgumentException("The status report refers to message [{$report['original_message_id']}], not to payment batch [{$batch->batch_reference}].");
            }

            $lines = PaymentLine::where('payment_batch_id', $batch->id)->where('status', 'pending')->get();
            $accepted = [];
            $rejected = [];

            foreach ($lines as $line) {
                $code = $report['transactions'][(string) $line->end_to_end_id] ?? $report['group_status'];

                if (in_array($code, self::PAIN002_EXECUTED, true)) {
                    $accepted[] = (string) $line->id;
                } elseif ($code === 'RJCT') {
                    $rejected[] = (string) $line->id;
                }
            }

            return $this->applyBankOutcome($batch, $accepted, $rejected, $report['message_id'], (string) $report['group_status'], null, [
                'report' => $report,
                'imported_by' => $userId,
            ]);
        });
    }

    /**
     * @return array{message_id: string, original_message_id: string, group_status: ?string, transactions: array<string, string>}
     */
    private function parsePain002(string $xml): array
    {
        $dom = new DOMDocument;

        if (trim($xml) === '' || ! @$dom->loadXML($xml, LIBXML_NONET)) {
            throw new InvalidArgumentException('The file is not a valid pain.002 document.');
        }

        $xpath = new DOMXPath($dom);
        $text = static fn (string $query, ?DOMElement $context = null): ?string => ($node = $xpath->query($query, $context)?->item(0)) !== null ? trim((string) $node->textContent) : null;
        $originalMessageId = $text("//*[local-name()='OrgnlGrpInfAndSts']/*[local-name()='OrgnlMsgId']");

        if ($originalMessageId === null) {
            throw new InvalidArgumentException('The file is not a valid pain.002 document.');
        }

        $transactions = [];

        foreach ($xpath->query("//*[local-name()='TxInfAndSts']") ?: [] as $node) {
            $endToEndId = $text("*[local-name()='OrgnlEndToEndId']", $node);
            $status = $text("*[local-name()='TxSts']", $node);

            if ($endToEndId !== null && $status !== null) {
                $transactions[$endToEndId] = $status;
            }
        }

        return [
            'message_id' => (string) ($text("//*[local-name()='GrpHdr']/*[local-name()='MsgId']") ?? $originalMessageId),
            'original_message_id' => $originalMessageId,
            'group_status' => $text("//*[local-name()='OrgnlGrpInfAndSts']/*[local-name()='GrpSts']") ?? $text("//*[local-name()='OrgnlPmtInfAndSts']/*[local-name()='PmtInfSts']"),
            'transactions' => $transactions,
        ];
    }

    /**
     * The only place where a payment becomes real: the money leaves the balance and the ledger hears about it.
     *
     * @param  list<string>  $executedLineIds
     * @param  list<string>  $rejectedLineIds
     * @param  array<string, mixed>  $payload
     */
    /**
     * Ri.Ba. batches collect money: they have their own file and outcome, and must never reach the outflow path.
     *
     * @throws InvalidArgumentException
     */
    private function assertNotRiba(PaymentBatch $batch): void
    {
        if ($batch->payment_method === 'riba') {
            throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] is a Ri.Ba. collection: use the Ri.Ba. routes.");
        }
    }

    private function applyBankOutcome(PaymentBatch $batch, array $executedLineIds, array $rejectedLineIds, string $bankReference, string $statusCode, ?string $valueDate, array $payload): PaymentBatch
    {
        $this->assertNotRiba($batch);

        if (! in_array(strtolower((string) $batch->status), ['exported', 'partially_executed'], true)) {
            throw new InvalidArgumentException("Payment batch [{$batch->batch_reference}] has no exported file waiting for an outcome.");
        }

        $before = $batch->toArray();
        $executedAmount = (float) PaymentLine::whereIn('id', $executedLineIds)->sum('amount');

        if ($executedLineIds !== []) {
            PaymentLine::whereIn('id', $executedLineIds)->update(['status' => 'executed']);
            $this->positionService->updateBalance((string) $batch->bank_account_id, -1 * $executedAmount, 'payment_execution');
        }

        if ($rejectedLineIds !== []) {
            PaymentLine::whereIn('id', $rejectedLineIds)->update(['status' => 'rejected', 'error_code' => 'RJCT', 'error_message' => 'Rejected by the bank']);
        }

        $lines = PaymentLine::where('payment_batch_id', $batch->id)->get(['status']);
        $hasPending = $lines->contains('status', 'pending');
        $hasExecuted = $lines->contains('status', 'executed');

        $batch->update(['status' => match (true) {
            $hasPending => $hasExecuted ? 'partially_executed' : 'exported',
            $hasExecuted && $lines->contains('status', 'rejected') => 'partially_executed',
            $hasExecuted => 'executed',
            default => 'rejected',
        }]);

        PaymentExecution::where('payment_batch_id', $batch->id)->latest()->first()?->update([
            'bank_transmission_reference' => $bankReference,
            'pain002_status_code' => mb_substr($statusCode, 0, 20),
            'clearing_timestamp' => $executedLineIds !== [] ? now() : null,
            'response_payload_json' => $payload,
        ]);

        if ($executedLineIds !== []) {
            $event = new PaymentExecutedEvent(
                paymentBatchId: (string) $batch->id,
                bankAccountId: (string) $batch->bank_account_id,
                totalAmount: $executedAmount,
                currency: (string) $batch->currency,
                protocol: (string) (PaymentExecution::where('payment_batch_id', $batch->id)->latest()->value('protocol') ?? 'sepa_pain001'),
                bankTransmissionReference: $bankReference,
                tenantId: (string) $batch->tenant_id,
                companyId: (string) ($batch->company_id ?? ''),
                executedLineIds: $executedLineIds,
                valueDate: $valueDate ?? now()->toDateString(),
            );
            $this->dispatcher->publish($event);
            event($event);
        }

        $this->auditLedger->record(
            action: 'payment_batch_outcome_recorded',
            model: $batch,
            payloadBefore: $before,
            payloadAfter: $batch->toArray(),
            moduleCode: 'MODULE_24_TREASURY'
        );

        return $batch->refresh();
    }

    public function cancelBatch(string $paymentBatchId, string $reason, ?string $userId = null): PaymentBatch
    {
        return DB::transaction(function () use ($paymentBatchId, $reason) {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::where('id', $paymentBatchId)->lockForUpdate()->firstOrFail();

            $status = strtolower((string) $batch->status);
            if (in_array($status, ['partially_executed', 'executed'], true)) {
                throw new InvalidArgumentException('Cannot cancel a payment batch the bank has executed.');
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
