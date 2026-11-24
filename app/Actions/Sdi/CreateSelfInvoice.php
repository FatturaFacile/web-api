<?php

declare(strict_types=1);

namespace App\Actions\Sdi;

use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Services\Invoicing\InvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\SDI\Enums\TipoDocumento;

/**
 * Issues the integration or self-invoice of a received supplier invoice: the supplier stays the counterparty, the
 * received document is the reference, and its taxable becomes the only line, with the VAT the tenant has to account for.
 */
class CreateSelfInvoice
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /**
     * @param  array{document_type: string, date: string, tax_rate: float|int|string, vat_nature?: ?string, description?: ?string, notes?: ?string}  $data
     *
     * @throws ValidationException when the type cannot start from a received invoice, or one was already issued
     */
    public function execute(PassiveInvoice $source, array $data): Invoice
    {
        $type = TipoDocumento::from($data['document_type']);

        if (! $type->canStartFromPassiveInvoice()) {
            throw ValidationException::withMessages(['document_type' => __('sdi.fiscal.not_a_self_invoice_source', ['type' => $type->value])]);
        }

        return DB::transaction(function () use ($source, $data, $type): Invoice {
            $locked = PassiveInvoice::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            $exists = Invoice::query()
                ->where('source_passive_invoice_id', $locked->id)
                ->where('document_type', $type->value)
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages(['document_type' => __('sdi.fiscal.self_invoice_exists', ['type' => $type->value])]);
            }

            $taxable = $locked->total_net ?? $locked->total_amount;

            return $this->invoices->createInvoice([
                'contact_id' => $locked->supplier_id,
                'invoice_type' => 'standard',
                'document_type' => $type->value,
                'source_passive_invoice_id' => $locked->id,
                'vat_nature' => $data['vat_nature'] ?? null,
                'date' => $data['date'],
                'currency' => $locked->currency ?: 'EUR',
                'exchange_rate' => $locked->exchange_rate ?? 1,
                'jurisdiction' => 'IT',
                'notes' => $data['notes'] ?? null,
                'items' => [[
                    'description' => $data['description'] ?? $type->description().' '.$locked->invoice_number,
                    'quantity' => 1,
                    'unit_price' => $taxable,
                    'tax_rate' => $data['tax_rate'],
                ]],
            ]);
        });
    }
}
