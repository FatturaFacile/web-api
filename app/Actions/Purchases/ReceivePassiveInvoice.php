<?php

declare(strict_types=1);

namespace App\Actions\Purchases;

use App\Models\Contact;
use App\Models\PassiveInvoice;
use App\Services\ItalianSdi\FatturaPaParser;
use App\Services\Sales\DocumentEventRecorder;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\GeneralLedger\Actions\PostPassiveInvoiceAction;

class ReceivePassiveInvoice
{
    public function __construct(
        private readonly FatturaPaParser $parser,
        private readonly TenantContext $tenantContext,
        private readonly DocumentEventRecorder $events,
        private readonly PostPassiveInvoiceAction $postToLedger,
    ) {}

    /**
     * Register the documents of an incoming FatturaPA file for the current tenant. The supplier is matched by VAT
     * number (and created when unknown); documents already received (same supplier, number, date, type and total)
     * are returned instead of duplicated. TD04 documents are stored as supplier credit notes.
     *
     * @return array<int, array{invoice: PassiveInvoice, duplicate: bool}>
     *
     * The ledger entry is posted with the document. An upload fails when it cannot be posted; a file pushed by SDI
     * cannot be refused, so `$deferLedgerErrors` keeps the document and logs the problem, and the pending-documents
     * command posts it once the period or the rate is there.
     *
     * @throws InvalidArgumentException when the XML is not a FatturaPA document
     * @throws ValidationException when the invoice is addressed to another company
     */
    public function execute(string $xml, ?string $fileName = null, bool $deferLedgerErrors = false): array
    {
        $parsed = $this->parser->parse($xml);
        $tenant = $this->tenantContext->getTenant();

        $this->assertAddressedToTenant($parsed, (string) $tenant?->vat_number, (string) $tenant?->fiscal_code);

        return DB::transaction(function () use ($parsed, $xml, $fileName, $tenant, $deferLedgerErrors): array {
            $supplier = $this->resolveSupplier($parsed['supplier']);
            $results = [];

            foreach ($parsed['documents'] as $document) {
                $hash = hash('sha256', implode('|', [
                    $supplier->id, $document['document_type'], $document['number'], $document['date'], number_format($document['total'], 2, '.', ''),
                ]));

                $existing = PassiveInvoice::where('document_hash', $hash)->first();

                if ($existing !== null) {
                    $results[] = ['invoice' => $existing, 'duplicate' => true];

                    continue;
                }

                $invoice = PassiveInvoice::create([
                    'tenant_id' => $tenant->id,
                    'supplier_id' => $supplier->id,
                    'invoice_number' => $document['number'],
                    'date' => $document['date'],
                    'due_date' => $document['due_date'],
                    'total_amount' => $document['total'],
                    'total_net' => $document['net'],
                    'total_tax' => $document['tax'],
                    'currency' => $document['currency'],
                    'document_type' => $document['document_type'],
                    'payment_status' => 'unpaid',
                    'document_hash' => $hash,
                    'sdi_file_name' => $fileName,
                    'xml_payload' => $xml,
                    'withholdings' => $document['withholdings'] === [] ? null : $document['withholdings'],
                    'withholding_tax_amount' => number_format(array_sum(array_map(static fn (array $withholding): float => (float) $withholding['amount'], $document['withholdings'])), 2, '.', ''),
                ]);

                $this->post($invoice, $deferLedgerErrors);
                $this->events->record($invoice, 'received', ['file_name' => $fileName, 'document_type' => $document['document_type']]);
                $results[] = ['invoice' => $invoice, 'duplicate' => false];
            }

            return $results;
        });
    }

    private function post(PassiveInvoice $invoice, bool $deferLedgerErrors): void
    {
        try {
            $this->postToLedger->execute($invoice);
        } catch (InvalidArgumentException $e) {
            if (! $deferLedgerErrors) {
                throw $e;
            }

            Log::warning('Received supplier invoice waits for the ledger.', ['passive_invoice_id' => $invoice->id, 'reason' => $e->getMessage()]);
        }
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function assertAddressedToTenant(array $parsed, string $tenantVat, string $tenantFiscalCode): void
    {
        $normalize = fn (?string $value): string => strtoupper((string) preg_replace('/^IT/i', '', trim((string) $value)));

        $matchesVat = $parsed['recipient_vat'] !== null && $normalize($parsed['recipient_vat']) === $normalize($tenantVat);
        $matchesFiscalCode = $parsed['recipient_fiscal_code'] !== null && $normalize($parsed['recipient_fiscal_code']) === $normalize($tenantFiscalCode);

        if (! $matchesVat && ! $matchesFiscalCode) {
            throw ValidationException::withMessages(['file' => __('purchases.recipient_mismatch')]);
        }
    }

    /**
     * @param  array{country: string, vat: ?string, fiscal_code: ?string, name: string}  $data
     */
    private function resolveSupplier(array $data): Contact
    {
        $query = Contact::query();

        if ($data['vat'] !== null) {
            $query->whereIn('vat_number', [$data['vat'], $data['country'].$data['vat']]);
        } elseif ($data['fiscal_code'] !== null) {
            $query->where('fiscal_code', $data['fiscal_code']);
        }

        $supplier = ($data['vat'] !== null || $data['fiscal_code'] !== null) ? $query->first() : null;

        if ($supplier !== null) {
            if ($supplier->type === 'client' || $supplier->type === 'customer') {
                $supplier->update(['type' => 'both']);
            }

            return $supplier;
        }

        return Contact::create([
            'type' => 'supplier',
            'country' => strtoupper($data['country']),
            'company_name' => $data['name'] !== '' ? $data['name'] : $data['vat'],
            'vat_number' => $data['vat'],
            'fiscal_code' => $data['fiscal_code'],
        ]);
    }
}
