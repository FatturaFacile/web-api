<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Xml;

use App\Contracts\SwissQrBill\SwissQrBillGeneratorInterface;
use App\Exceptions\QrBillGenerationException;
use App\Models\Invoice;
use App\Services\SwissQrBill\GeneratedQrBill;
use DOMDocument;
use DOMElement;
use Modules\SwissEbill\Application\Dto\BuiltEbillPayload;
use Modules\SwissEbill\Application\Services\EbillCourtesyPdfService;
use Modules\SwissEbill\Application\Services\EbillPreflightService;
use Modules\SwissEbill\Domain\Enums\EbillChannelType;
use Modules\SwissEbill\Domain\Exceptions\EbillValidationException;
use Modules\SwissEbill\Domain\Support\SwissUid;
use Modules\SwissEbill\Models\EbillSetting;

/**
 * Builds the structured SIX eBill XML of an invoice: biller and recipient metadata, lines with Swiss VAT rates, the VAT
 * summary, the totals and, when enabled, the courtesy PDF (Base64, SHA-256) as an embedded document. The result is checked
 * against the XSD and the size limit before it is returned; missing data surfaces as an EbillValidationException.
 */
final class EbillPayloadBuilderService
{
    public function __construct(
        private readonly EbillPreflightService $preflight,
        private readonly EbillCourtesyPdfService $pdfs,
        private readonly EbillPayloadValidator $validator,
        private readonly SwissQrBillGeneratorInterface $qrBills,
    ) {}

    /**
     * @throws EbillValidationException
     */
    public function build(Invoice $invoice, EbillSetting $setting, string $transmissionId): BuiltEbillPayload
    {
        $violations = $this->preflight->violations($invoice, $setting);

        if ($violations !== []) {
            throw new EbillValidationException($violations);
        }

        $contact = $invoice->contact;
        $tenant = $invoice->tenant;

        try {
            $qr = $this->qrBills->generate($invoice);
        } catch (QrBillGenerationException) {
            throw new EbillValidationException([['code' => 'qr_bill_invalid', 'field' => 'biller.qr_bill']]);
        }

        $channel = EbillChannelType::tryFrom((string) $contact->ebill_channel_type) ?? EbillChannelType::B2cPrivate;
        $pdf = $setting->attach_courtesy_pdf ? $this->pdfs->render($invoice) : null;

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = false;
        $root = $document->createElementNS(EbillPayloadValidator::NAMESPACE_URI, 'EbillInvoice');
        $root->setAttribute('version', $setting->default_ebinterface_version);
        $document->appendChild($root);

        $header = $this->node($document, $root, 'Header');
        $this->text($document, $header, 'TransmissionId', $transmissionId);
        $this->text($document, $header, 'BillerID', (string) $setting->biller_id);
        $this->text($document, $header, 'RecipientID', (string) $contact->ebill_recipient_id);
        $this->text($document, $header, 'Channel', $channel === EbillChannelType::B2bEnterprise ? 'B2B' : 'B2C');
        $this->text($document, $header, 'CreatedAt', now()->utc()->format('Y-m-d\TH:i:s\Z'));

        $biller = $this->node($document, $root, 'Biller');
        $this->text($document, $biller, 'Name', $qr->creditorLines[0] ?? (string) ($tenant->legal_name ?: $tenant->name));
        $this->text($document, $biller, 'UID', (string) SwissUid::normalize((string) $tenant->uid_number));

        if (trim((string) $tenant->vat_number) !== '') {
            $this->text($document, $biller, 'VATNumber', trim((string) $tenant->vat_number));
        }

        $this->billerAddress($document, $biller, $qr);
        $account = $this->node($document, $biller, 'PaymentAccount');
        $this->text($document, $account, 'IBAN', $qr->iban);
        $reference = $this->text($document, $account, 'Reference', (string) ($qr->reference ?? ''));
        $reference->setAttribute('type', $qr->referenceType);

        $this->recipient($document, $root, $invoice);
        $this->invoice($document, $root, $invoice);

        if ($pdf !== null) {
            $additional = $this->node($document, $root, 'AdditionalInformation');
            $embedded = $this->text($document, $additional, 'EmbeddedDocument', base64_encode($pdf['content']));
            $embedded->setAttribute('mimeType', 'application/pdf');
            $embedded->setAttribute('filename', $pdf['filename']);
            $embedded->setAttribute('sha256', $pdf['sha256']);
            $embedded->setAttribute('sizeBytes', (string) $pdf['size']);
        }

        $xml = (string) $document->saveXML();

        if (strlen($xml) > (int) config('swiss_ebill.max_payload_bytes')) {
            throw new EbillValidationException([['code' => 'payload_too_large', 'field' => 'payload']]);
        }

        $this->validator->assertValid($xml);

        return new BuiltEbillPayload($xml, hash('sha256', $xml), (string) $setting->biller_id, (string) $contact->ebill_recipient_id, $channel, $pdf['sha256'] ?? null, $pdf['size'] ?? null, $setting->default_ebinterface_version);
    }

    private function billerAddress(DOMDocument $document, DOMElement $biller, GeneratedQrBill $qr): void
    {
        $lines = array_values($qr->creditorLines);
        $locality = (string) end($lines);
        $street = $lines[1] ?? '';
        $postal = '';
        $city = $locality;

        if (preg_match('/^(?:([A-Z]{2})-)?(\d{4,5})\s+(.+)$/u', $locality, $m) === 1) {
            $postal = $m[2];
            $city = $m[3];
        }

        $address = $this->node($document, $biller, 'Address');

        if (count($lines) > 2 && $street !== '') {
            $this->text($document, $address, 'Street', $street);
        }

        // The postal code must exist for a Swiss address: an unparsable locality line fails the schema (violation), never guessed.
        $this->text($document, $address, 'PostalCode', $postal);
        $this->text($document, $address, 'City', $city);
        $this->text($document, $address, 'Country', preg_match('/^([A-Z]{2})-/', $locality, $c) === 1 ? $c[1] : 'CH');
    }

    private function recipient(DOMDocument $document, DOMElement $root, Invoice $invoice): void
    {
        $contact = $invoice->contact;
        $node = $this->node($document, $root, 'Recipient');
        $this->text($document, $node, 'Name', EbillPreflightService::displayName($contact));

        $uid = SwissUid::normalize($contact->uid_number);

        if ($uid !== null) {
            $this->text($document, $node, 'UID', $uid);
        }

        if (trim((string) $contact->email) !== '') {
            $this->text($document, $node, 'Email', (string) $contact->email);
        }

        $source = EbillPreflightService::address($contact);
        $address = $this->node($document, $node, 'Address');

        if (trim((string) $source?->street) !== '') {
            $this->text($document, $address, 'Street', trim((string) $source?->street));
        }

        if (trim((string) $source?->house_number) !== '') {
            $this->text($document, $address, 'HouseNumber', trim((string) $source?->house_number));
        }

        $this->text($document, $address, 'PostalCode', trim((string) $source?->postal_code));
        $this->text($document, $address, 'City', trim((string) $source?->city));
        $this->text($document, $address, 'Country', strtoupper(trim((string) ($source?->country ?: $contact->country ?: 'CH'))));
    }

    private function invoice(DOMDocument $document, DOMElement $root, Invoice $invoice): void
    {
        $node = $this->node($document, $root, 'Invoice');
        $this->text($document, $node, 'Number', (string) $invoice->invoice_number);
        $this->text($document, $node, 'IssueDate', $invoice->date->toDateString());
        $this->text($document, $node, 'DueDate', $invoice->due_date->toDateString());
        $this->text($document, $node, 'Currency', (string) $invoice->currency);

        if (trim((string) $invoice->payment_terms) !== '') {
            $this->text($document, $node, 'PaymentTerms', trim((string) $invoice->payment_terms));
        }

        $lines = $this->node($document, $node, 'Lines');
        $vat = [];

        foreach ($invoice->items->values() as $index => $item) {
            $line = $this->node($document, $lines, 'Line');
            $line->setAttribute('pos', (string) ($index + 1));
            $this->text($document, $line, 'Description', trim((string) $item->description) !== '' ? trim((string) $item->description) : '-');
            $this->text($document, $line, 'Quantity', $this->number((float) $item->quantity, 4));
            $this->text($document, $line, 'UnitPrice', $this->number((float) $item->unit_price, 4));
            $this->text($document, $line, 'DiscountPercent', $this->number((float) $item->discount_percentage, 2));
            $this->text($document, $line, 'VatRate', $this->number((float) $item->tax_rate, 2));
            $this->text($document, $line, 'NetAmount', $this->number((float) $item->line_total, 2));

            $key = $this->number((float) $item->tax_rate, 2);
            $vat[$key] = round(($vat[$key] ?? 0.0) + (float) $item->line_total, 2);
        }

        $summary = $this->node($document, $node, 'VatSummary');

        foreach ($vat as $rate => $taxable) {
            $entry = $this->node($document, $summary, 'Vat');
            $entry->setAttribute('rate', (string) $rate);
            $this->text($document, $entry, 'TaxableAmount', $this->number($taxable, 2));
            $this->text($document, $entry, 'TaxAmount', $this->number(round($taxable * (float) $rate / 100, 2), 2));
        }

        $totals = $this->node($document, $node, 'Totals');
        $this->text($document, $totals, 'Net', $this->number((float) $invoice->total_net, 2));
        $this->text($document, $totals, 'Tax', $this->number((float) $invoice->total_tax, 2));
        $this->text($document, $totals, 'Gross', $this->number((float) $invoice->total_gross, 2));
    }

    private function node(DOMDocument $document, DOMElement $parent, string $name): DOMElement
    {
        $element = $document->createElementNS(EbillPayloadValidator::NAMESPACE_URI, $name);
        $parent->appendChild($element);

        return $element;
    }

    private function text(DOMDocument $document, DOMElement $parent, string $name, string $value): DOMElement
    {
        $element = $document->createElementNS(EbillPayloadValidator::NAMESPACE_URI, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);

        return $element;
    }

    private function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, '.', '');
    }
}
