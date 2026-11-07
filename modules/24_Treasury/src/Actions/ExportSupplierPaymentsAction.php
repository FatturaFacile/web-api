<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use App\Models\PassiveInvoice;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Treasury\Contracts\PaymentFactoryServiceInterface;
use Modules\Treasury\Models\BankAccount;
use Modules\Treasury\Models\PaymentBatch;

/**
 * Turns open supplier invoices into a pain.001 file. What is missing is refused, never filled in: without a bank
 * account or the IBAN of a supplier there is no file.
 */
final readonly class ExportSupplierPaymentsAction
{
    public function __construct(
        private PaymentFactoryServiceInterface $paymentFactory,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @param  array{invoice_ids: list<string>, format: string, execution_date?: ?string, bank_account_id?: ?string}  $data
     * @return array{batch_id: string, message_id: ?string, format: string, status: string, xml_preview: string}
     *
     * @throws ValidationException
     */
    public function export(array $data, string $userId, ?string $ipAddress): array
    {
        $tenantId = (string) $this->tenantContext->getTenantId();
        $invoices = PassiveInvoice::with('supplier')->whereIn('id', $data['invoice_ids'])->get();

        if ($invoices->count() !== count(array_unique($data['invoice_ids']))) {
            throw ValidationException::withMessages(['invoice_ids' => 'Some supplier invoices do not exist.']);
        }

        $currencies = $invoices->pluck('currency')->unique();

        if ($currencies->count() !== 1) {
            throw ValidationException::withMessages(['invoice_ids' => 'The invoices of a payment file must share one currency.']);
        }

        $currency = (string) $currencies->first();
        $bankAccount = $this->bankAccount($tenantId, $currency, $data['bank_account_id'] ?? null);
        $lines = $invoices->map(fn (PassiveInvoice $invoice): array => $this->line($invoice))->all();

        $batch = $this->paymentFactory->createExportedBatch([
            'tenant_id' => $tenantId,
            'company_id' => $tenantId,
            'bank_account_id' => $bankAccount->id,
            'execution_date' => $data['execution_date'] ?? now()->addDay()->toDateString(),
            'currency' => $currency,
            'format' => $data['format'],
        ], $lines, $userId, $ipAddress);

        return $this->summary($batch, $data['format']);
    }

    /**
     * @return array{batch_id: string, message_id: ?string, format: string, status: string, xml_preview: string}
     */
    private function summary(PaymentBatch $batch, string $format): array
    {
        return [
            'batch_id' => (string) $batch->id,
            'message_id' => $batch->pain001_message_id,
            'format' => $format,
            'status' => (string) $batch->status,
            'xml_preview' => (string) $batch->pain001_xml,
        ];
    }

    private function bankAccount(string $tenantId, string $currency, ?string $bankAccountId): BankAccount
    {
        $account = BankAccount::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->when($bankAccountId, fn ($query, $id) => $query->whereKey($id), fn ($query) => $query->where('currency', $currency))
            ->orderByDesc('is_default')
            ->first();

        return $account ?? throw ValidationException::withMessages(['bank_account_id' => "No active bank account in {$currency} to debit."]);
    }

    /**
     * @return array<string, mixed>
     */
    private function line(PassiveInvoice $invoice): array
    {
        $balance = round((float) $invoice->total_amount - (float) $invoice->total_paid, 2);

        if ($balance <= 0 || $invoice->document_type === 'TD04') {
            throw ValidationException::withMessages(['invoice_ids' => "Invoice {$invoice->invoice_number} has nothing to pay."]);
        }

        if ($invoice->isPaymentBlocked()) {
            throw ValidationException::withMessages(['invoice_ids' => "Invoice {$invoice->invoice_number} is not approved for payment."]);
        }

        $qrLines = $invoice->qr_raw_payload !== null ? preg_split('/\r\n|\r|\n/', trim((string) $invoice->qr_raw_payload)) : [];
        $supplier = $invoice->supplier;
        $iban = ($qrLines[3] ?? '') ?: ($supplier?->iban ?: $supplier?->qr_iban);

        if (empty($iban)) {
            throw ValidationException::withMessages(['invoice_ids' => "Supplier of invoice {$invoice->invoice_number} has no IBAN."]);
        }

        $reference = $qrLines[28] ?? '';

        return [
            'recipient_type' => 'supplier',
            'recipient_id' => $invoice->supplier_id,
            'recipient_name' => (string) ($supplier?->company_name ?: trim($supplier?->first_name.' '.$supplier?->last_name)),
            'recipient_iban' => $iban,
            'amount' => $balance,
            'end_to_end_id' => 'E2E-'.strtoupper(Str::random(12)),
            'remittance_reference' => "Invoice {$invoice->invoice_number}",
            'structured_creditor_ref' => $reference !== '' ? $reference : null,
            'invoice_id' => $invoice->id,
        ];
    }
}
