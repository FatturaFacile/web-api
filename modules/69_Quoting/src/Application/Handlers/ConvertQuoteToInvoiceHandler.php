<?php

declare(strict_types=1);

namespace Modules\Quoting\Application\Handlers;

use Illuminate\Support\Facades\DB;
use Modules\Invoicing\Contracts\InvoicingServiceInterface;
use Modules\Invoicing\Models\SalesInvoice;
use Modules\Quoting\Application\Commands\ConvertQuoteToInvoiceCommand;
use Modules\Quoting\Application\Services\QuoteBillableLines;
use Modules\Quoting\Domain\Enums\QuoteStatus;
use Modules\Quoting\Domain\Events\QuoteConvertedToInvoice;
use Modules\Quoting\Domain\Exceptions\QuoteException;
use Modules\Quoting\Infrastructure\Models\CpqQuote;
use Modules\Quoting\Infrastructure\Services\QuoteAuditTrail;

/**
 * Turns an accepted quote into a DRAFT sales invoice (the whole quote, or a down payment of a percentage) through the single
 * writer of the sales documents, so numbering, VAT and fiscal rules are the ones of every other invoice. The draft is left for
 * the accountant to issue. A quote converts once, into an order or an invoice: the quote row is locked and the link is unique,
 * so a double click or a redelivered event returns the same invoice.
 */
final class ConvertQuoteToInvoiceHandler
{
    public function __construct(
        private readonly InvoicingServiceInterface $invoicing,
        private readonly QuoteBillableLines $billable,
        private readonly QuoteAuditTrail $audit,
    ) {}

    public function handle(ConvertQuoteToInvoiceCommand $command): SalesInvoice
    {
        $percent = $this->validPercent($command->downPaymentPercent);
        $isDeposit = $percent !== null;
        $label = $percent === null ? 'Invoice' : "Down payment {$percent}%";

        $result = DB::transaction(function () use ($command, $percent, $isDeposit, $label): array {
            $quote = CpqQuote::query()->whereKey($command->quoteId)->lockForUpdate()->firstOrFail();

            if ($quote->converted_invoice_id !== null) {
                return [SalesInvoice::query()->findOrFail($quote->converted_invoice_id), false, $quote];
            }

            if ($quote->converted_sales_order_id !== null) {
                throw new QuoteException("Quote {$quote->quote_number} was already converted into a sales order.");
            }

            if ($quote->status !== QuoteStatus::Accepted) {
                throw new QuoteException("Quote {$quote->quote_number} is {$quote->status->value}: only an accepted quote can be converted.");
            }

            if ($quote->contact_id === null) {
                throw new QuoteException('The quote has no customer record: link a contact before converting it.');
            }

            $items = $isDeposit ? $this->billable->downPayment($quote, (string) $percent) : $this->billable->of($quote);
            if ($items === []) {
                throw new QuoteException('The accepted selection has no billable line.');
            }

            $invoice = $this->invoicing->createInvoice([
                'tenant_id' => (string) $quote->tenant_id,
                'contact_id' => (string) $quote->contact_id,
                'currency' => $quote->currency,
                'jurisdiction' => $quote->jurisdiction === 'CH' ? 'CH' : 'IT',
                'is_deposit' => $isDeposit,
                'incoterm' => $quote->incoterms,
                'notes' => $label." from quote {$quote->quote_number}; signed proposal SHA-256 {$quote->signed_document_hash}"
                    .($quote->payment_terms === null ? '' : "; payment terms: {$quote->payment_terms}"),
                'created_by' => $command->userId,
            ], array_map(static fn (array $item): array => array_diff_key($item, ['net' => 1]), $items));

            $quote->forceFill(['converted_invoice_id' => (string) $invoice->id, 'status' => QuoteStatus::Converted->value])->save();
            $this->audit->record($quote, 'converted_to_invoice', $command->userId, null, ['invoice_id' => (string) $invoice->id, 'down_payment_percent' => $percent]);

            return [$invoice, true, $quote];
        });

        if ($result[1]) {
            event(new QuoteConvertedToInvoice($result[2]->id, (string) $result[0]->id));
        }

        return $result[0];
    }

    private function validPercent(?string $percent): ?string
    {
        if ($percent === null) {
            return null;
        }

        if (! is_numeric($percent) || bccomp($percent, '0', 4) <= 0 || bccomp($percent, '100', 4) > 0) {
            throw new QuoteException('A down payment is a percentage above 0 and up to 100.');
        }

        return $percent;
    }
}
