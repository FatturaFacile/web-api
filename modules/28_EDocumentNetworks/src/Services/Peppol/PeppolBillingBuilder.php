<?php

declare(strict_types=1);

namespace Modules\EDocumentNetworks\Services\Peppol;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use App\Services\Sales\VatSummaryCalculator;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Builds the Peppol BIS Billing 3.0 document (UBL 2.1, EN 16931) of a sales invoice or credit note. The amounts are
 * recomputed from the lines with exact decimals, so the tax subtotals and the monetary totals reconcile to the cent
 * whatever the totals stored on the invoice say. It never fills in missing master data: what Peppol requires and the
 * records do not hold is an error. It builds the file only: nothing is transmitted to an Access Point.
 */
final class PeppolBillingBuilder
{
    public const string CUSTOMIZATION_ID = 'urn:cen.eu:en16931:2017#compliant#urn:fdc:peppol.eu:2017:poid:3.0';

    public const string PROFILE_ID = 'urn:fdc:peppol.eu:2017:poid:billing:01:1.0';

    public const string NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    public const string NS_CREDIT_NOTE = 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2';

    public const string NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public const string NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    /** Peppol endpoint scheme (EAS code) of the VAT number, by country. */
    private const array VAT_ENDPOINT_SCHEMES = [
        'IT' => '0211',
        'BE' => '9925',
        'DE' => '9930',
        'FR' => '9957',
        'NL' => '9944',
        'ES' => '9920',
        'AT' => '9914',
        'CH' => '0183',
    ];

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly VatSummaryCalculator $vat = new VatSummaryCalculator,
        private readonly FatturaPaBuilderService $fatturaPa = new FatturaPaBuilderService,
    ) {}

    /**
     * Whether the invoice is exported as a credit note (381) instead of an invoice (380).
     */
    public function isCreditNote(Invoice $invoice): bool
    {
        return $invoice->invoice_type === 'credit_note';
    }

    /**
     * The Peppol participant identifier ("scheme:value") of the customer, as the envelope of a network expects it.
     *
     * @throws InvalidArgumentException when the customer has neither a VAT number nor an email to address it
     */
    public function recipientIdentifier(Invoice $invoice): string
    {
        $contact = $invoice->contact ?? throw new InvalidArgumentException('The invoice has no customer.');
        $endpoint = $this->contactEndpoint($contact);

        return $endpoint['scheme'].':'.$endpoint['value'];
    }

    /**
     * Build and serialize the UBL 2.1 XML.
     *
     * @throws InvalidArgumentException when a mandatory value is missing
     */
    public function build(Invoice $invoice): string
    {
        $tenant = $invoice->tenant ?? throw new InvalidArgumentException('Invoice must belong to a valid tenant.');
        $contact = $invoice->contact ?? throw new InvalidArgumentException('The invoice has no customer.');
        $items = $invoice->items;

        if ($items->isEmpty()) {
            throw new InvalidArgumentException('The invoice has no lines.');
        }

        $isCreditNote = $this->isCreditNote($invoice);
        $currency = strtoupper((string) ($invoice->currency ?: $tenant->default_currency ?: 'EUR'));
        $lines = $this->lineModels($invoice, $items->all());
        $summary = $this->vat->summarize(array_map(static fn (array $line): array => ['rate' => $line['rate'], 'taxable' => $line['net'], 'nature' => $line['group']], $lines));
        $lineTotal = '0';
        $taxTotal = '0';

        foreach ($lines as $line) {
            $lineTotal = Decimal::add($lineTotal, $line['net']);
        }

        foreach ($summary as $group) {
            $taxTotal = Decimal::add($taxTotal, $group['tax']);
        }

        $lineTotal = Decimal::round2($lineTotal);
        $taxTotal = Decimal::round2($taxTotal);
        $taxInclusive = Decimal::round2(Decimal::add($lineTotal, $taxTotal));
        $rounding = Decimal::round2((string) ($invoice->rounding_amount ?? '0'));
        $payable = Decimal::round2(Decimal::add($taxInclusive, $rounding));

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS($isCreditNote ? self::NS_CREDIT_NOTE : self::NS_INVOICE, $isCreditNote ? 'CreditNote' : 'Invoice');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cac', self::NS_CAC);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:cbc', self::NS_CBC);
        $dom->appendChild($root);

        $this->cbc($root, 'CustomizationID', self::CUSTOMIZATION_ID);
        $this->cbc($root, 'ProfileID', self::PROFILE_ID);
        $this->cbc($root, 'ID', $this->required($invoice->invoice_number, 'invoice number'));
        $this->cbc($root, 'IssueDate', $this->required($invoice->date?->format('Y-m-d'), 'invoice date'));

        if (! $isCreditNote && $invoice->due_date !== null) {
            $this->cbc($root, 'DueDate', $invoice->due_date->format('Y-m-d'));
        }

        $this->cbc($root, $isCreditNote ? 'CreditNoteTypeCode' : 'InvoiceTypeCode', $isCreditNote ? '381' : '380');

        if (! empty($invoice->notes)) {
            $this->cbc($root, 'Note', $invoice->notes);
        }

        $this->cbc($root, 'DocumentCurrencyCode', $currency);
        $this->cbc($root, 'BuyerReference', (string) $invoice->invoice_number);

        $related = $isCreditNote ? $invoice->relatedInvoice : null;

        if ($related !== null) {
            $reference = $this->cac($this->cac($root, 'BillingReference'), 'InvoiceDocumentReference');
            $this->cbc($reference, 'ID', (string) $related->invoice_number);

            if ($related->date !== null) {
                $this->cbc($reference, 'IssueDate', $related->date->format('Y-m-d'));
            }
        }

        $this->party($this->cac($root, 'AccountingSupplierParty'), $this->tenantParty($tenant));
        $this->party($this->cac($root, 'AccountingCustomerParty'), $this->contactParty($contact));
        $this->paymentMeans($root, $invoice, $tenant, $isCreditNote);

        $taxTotalNode = $this->cac($root, 'TaxTotal');
        $this->amount($taxTotalNode, 'TaxAmount', $taxTotal, $currency);

        foreach ($summary as $group) {
            [$category, $exemption] = array_pad(explode(':', (string) $group['nature'], 2), 2, null);
            $subtotal = $this->cac($taxTotalNode, 'TaxSubtotal');
            $this->amount($subtotal, 'TaxableAmount', $group['taxable'], $currency);
            $this->amount($subtotal, 'TaxAmount', $group['tax'], $currency);
            $this->taxCategory($subtotal, (string) $category, $group['rate'], $exemption);
        }

        $monetary = $this->cac($root, 'LegalMonetaryTotal');
        $this->amount($monetary, 'LineExtensionAmount', $lineTotal, $currency);
        $this->amount($monetary, 'TaxExclusiveAmount', $lineTotal, $currency);
        $this->amount($monetary, 'TaxInclusiveAmount', $taxInclusive, $currency);

        if (! Decimal::isZero($rounding)) {
            $this->amount($monetary, 'PayableRoundingAmount', $rounding, $currency);
        }

        $this->amount($monetary, 'PayableAmount', $payable, $currency);

        foreach ($lines as $number => $line) {
            $this->documentLine($root, $number + 1, $line, $isCreditNote, $currency);
        }

        return (string) $dom->saveXML();
    }

    /**
     * The lines with their net amount, tax group and discount, before any XML is written.
     *
     * @param  list<InvoiceItem>  $items
     * @return list<array{item: InvoiceItem, net: string, rate: string, category: string, exemption: string, group: string, allowance: string, gross: string}>
     */
    private function lineModels(Invoice $invoice, array $items): array
    {
        $lines = [];

        foreach ($items as $item) {
            $rate = $invoice->reverse_charge ? '0.00' : Decimal::round2((string) $item->tax_rate);
            $nature = $this->fatturaPa->lineNature($invoice, (float) $rate);
            [$category, $exemption] = $this->taxClassification($rate, $nature, (bool) $invoice->reverse_charge);
            $net = Decimal::round2((string) $item->line_total);
            $gross = Decimal::round2(Decimal::mul((string) $item->quantity, (string) $item->unit_price));

            $lines[] = [
                'item' => $item,
                'net' => $net,
                'rate' => $rate,
                'category' => $category,
                'exemption' => $exemption,
                'group' => $category.':'.$exemption,
                'allowance' => Decimal::round2(Decimal::sub($gross, $net)),
                'gross' => $gross,
            ];
        }

        return $lines;
    }

    /**
     * The Peppol VAT category and the VATEX code of a line: S standard, Z zero rated, E exempt, AE reverse charge,
     * G export outside the EU and O outside the scope of VAT.
     *
     * @return array{0: string, 1: string}
     */
    private function taxClassification(string $rate, ?string $nature, bool $reverseCharge): array
    {
        if ($reverseCharge || str_starts_with((string) $nature, 'N6')) {
            return ['AE', 'VATEX-EU-AE'];
        }

        if (! Decimal::isZero($rate)) {
            return ['S', ''];
        }

        return match (true) {
            $nature === null || $nature === '' => ['Z', ''],
            str_starts_with($nature, 'N1'), str_starts_with($nature, 'N2') => ['O', 'VATEX-EU-O'],
            str_starts_with($nature, 'N3') => ['G', 'VATEX-EU-G'],
            default => ['E', 'VATEX-EU-132'],
        };
    }

    /**
     * @param  array{item: InvoiceItem, net: string, rate: string, category: string, exemption: string, allowance: string, gross: string}  $line
     */
    private function documentLine(DOMElement $root, int $number, array $line, bool $isCreditNote, string $currency): void
    {
        $item = $line['item'];
        $node = $this->cac($root, $isCreditNote ? 'CreditNoteLine' : 'InvoiceLine');
        $this->cbc($node, 'ID', (string) $number);
        $this->cbc($node, $isCreditNote ? 'CreditedQuantity' : 'InvoicedQuantity', $this->trimDecimal((string) $item->quantity), ['unitCode' => 'C62']);
        $this->amount($node, 'LineExtensionAmount', $line['net'], $currency);

        if (! Decimal::isZero($line['allowance'])) {
            $isCharge = Decimal::isNegative($line['allowance']);
            $allowance = $this->cac($node, 'AllowanceCharge');
            $this->cbc($allowance, 'ChargeIndicator', $isCharge ? 'true' : 'false');
            $this->cbc($allowance, 'AllowanceChargeReason', $isCharge ? 'Surcharge' : 'Discount');
            $this->amount($allowance, 'Amount', Decimal::round2(Decimal::abs($line['allowance'])), $currency);
            $this->amount($allowance, 'BaseAmount', $line['gross'], $currency);
        }

        $product = $this->cac($node, 'Item');
        $this->cbc($product, 'Description', (string) $item->description);
        $this->cbc($product, 'Name', mb_substr((string) $item->description, 0, 200));
        $category = $this->cac($product, 'ClassifiedTaxCategory');
        $this->cbc($category, 'ID', $line['category']);
        $this->cbc($category, 'Percent', $line['rate']);
        $this->cbc($this->cac($category, 'TaxScheme'), 'ID', 'VAT');

        $price = $this->cac($node, 'Price');
        $this->amount($price, 'PriceAmount', $this->trimDecimal((string) $item->unit_price), $currency);
    }

    /**
     * @param  array{name: string, endpoint: array{scheme: string, value: string}, vat: ?string, street: string, city: string, postal_code: string, subdivision: ?string, country: string, company_id: ?string}  $party
     */
    private function party(DOMElement $wrapper, array $party): void
    {
        $node = $this->cac($wrapper, 'Party');
        $this->cbc($node, 'EndpointID', $party['endpoint']['value'], ['schemeID' => $party['endpoint']['scheme']]);
        $this->cbc($this->cac($node, 'PartyName'), 'Name', $party['name']);

        $address = $this->cac($node, 'PostalAddress');
        $this->cbc($address, 'StreetName', $party['street']);
        $this->cbc($address, 'CityName', $party['city']);
        $this->cbc($address, 'PostalZone', $party['postal_code']);

        if (! empty($party['subdivision'])) {
            $this->cbc($address, 'CountrySubentity', $party['subdivision']);
        }

        $this->cbc($this->cac($address, 'Country'), 'IdentificationCode', $party['country']);

        if ($party['vat'] !== null) {
            $scheme = $this->cac($node, 'PartyTaxScheme');
            $this->cbc($scheme, 'CompanyID', $party['vat']);
            $this->cbc($this->cac($scheme, 'TaxScheme'), 'ID', 'VAT');
        }

        $legal = $this->cac($node, 'PartyLegalEntity');
        $this->cbc($legal, 'RegistrationName', $party['name']);

        if ($party['company_id'] !== null) {
            $this->cbc($legal, 'CompanyID', $party['company_id']);
        }
    }

    /**
     * @return array{name: string, endpoint: array{scheme: string, value: string}, vat: ?string, street: string, city: string, postal_code: string, subdivision: ?string, country: string, company_id: ?string}
     */
    private function tenantParty(Tenant $tenant): array
    {
        $country = strtoupper($tenant->country ?: 'IT');
        $address = $this->fatturaPa->tenantAddress($tenant);
        $vat = $this->vatWithCountry($this->required($tenant->vat_number, 'tenant vat_number'), $country);

        return [
            'name' => $this->required($tenant->legal_name ?: $tenant->name, 'tenant name'),
            'endpoint' => $this->vatEndpoint($vat, $country),
            'vat' => $vat,
            'street' => $this->required($address['street'], 'tenant street'),
            'city' => $this->required($address['city'], 'tenant city'),
            'postal_code' => $this->required($address['postal_code'], 'tenant postal_code'),
            'subdivision' => $address['province'],
            'country' => $country,
            'company_id' => $tenant->fiscal_code ?: null,
        ];
    }

    /**
     * @return array{name: string, endpoint: array{scheme: string, value: string}, vat: ?string, street: string, city: string, postal_code: string, subdivision: ?string, country: string, company_id: ?string}
     */
    private function contactParty(Contact $contact): array
    {
        $country = strtoupper($contact->country ?: 'IT');
        $address = $contact->addresses()->where('type', 'billing')->first();
        $name = $contact->company_name ?: trim($contact->first_name.' '.$contact->last_name);

        return [
            'name' => $this->required($name, 'customer name'),
            'endpoint' => $this->contactEndpoint($contact),
            'vat' => filled($contact->vat_number) ? $this->vatWithCountry((string) $contact->vat_number, $country) : null,
            'street' => $this->required($address?->street, 'customer billing street'),
            'city' => $this->required($address?->city, 'customer billing city'),
            'postal_code' => $this->required($address?->postal_code, 'customer billing postal_code'),
            'subdivision' => $address?->state_canton,
            'country' => $country,
            'company_id' => null,
        ];
    }

    /**
     * The endpoint of a customer: the VAT number under the scheme of its country, the email address (scheme EM) when
     * the country has no scheme here or no VAT number is known.
     *
     * @return array{scheme: string, value: string}
     */
    private function contactEndpoint(Contact $contact): array
    {
        $country = strtoupper($contact->country ?: 'IT');

        if (filled($contact->vat_number) && isset(self::VAT_ENDPOINT_SCHEMES[$country])) {
            return ['scheme' => self::VAT_ENDPOINT_SCHEMES[$country], 'value' => $this->vatWithCountry((string) $contact->vat_number, $country)];
        }

        if (filled($contact->email)) {
            return ['scheme' => 'EM', 'value' => (string) $contact->email];
        }

        throw new InvalidArgumentException('Missing Peppol endpoint of the customer: a VAT number of a supported country or an email address.');
    }

    /**
     * The endpoint of the supplier: its VAT number under the scheme of its country.
     *
     * @return array{scheme: string, value: string}
     */
    private function vatEndpoint(string $vat, string $country): array
    {
        throw_unless(isset(self::VAT_ENDPOINT_SCHEMES[$country]), InvalidArgumentException::class, "No Peppol endpoint scheme is configured for the VAT numbers of {$country}.");

        return ['scheme' => self::VAT_ENDPOINT_SCHEMES[$country], 'value' => $vat];
    }

    private function paymentMeans(DOMElement $root, Invoice $invoice, Tenant $tenant, bool $isCreditNote): void
    {
        $iban = (string) preg_replace('/\s+/', '', (string) ($invoice->bank_iban ?: $tenant->iban));

        if ($iban === '') {
            return;
        }

        $means = $this->cac($root, 'PaymentMeans');
        $this->cbc($means, 'PaymentMeansCode', '30');

        if ($isCreditNote && $invoice->due_date !== null) {
            $this->cbc($means, 'PaymentDueDate', $invoice->due_date->format('Y-m-d'));
        }

        $this->cbc($means, 'PaymentID', (string) $invoice->invoice_number);
        $this->cbc($this->cac($means, 'PayeeFinancialAccount'), 'ID', strtoupper($iban));
    }

    private function taxCategory(DOMElement $parent, string $category, string $rate, ?string $exemption): void
    {
        $node = $this->cac($parent, 'TaxCategory');
        $this->cbc($node, 'ID', $category);
        $this->cbc($node, 'Percent', $rate);

        if ($exemption !== null && $exemption !== '') {
            $this->cbc($node, 'TaxExemptionReasonCode', $exemption);
        }

        $this->cbc($this->cac($node, 'TaxScheme'), 'ID', 'VAT');
    }

    private function cac(DOMElement $parent, string $name): DOMElement
    {
        $node = $parent->ownerDocument->createElementNS(self::NS_CAC, 'cac:'.$name);
        $parent->appendChild($node);

        return $node;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private function cbc(DOMElement $parent, string $name, string $value, array $attributes = []): void
    {
        $node = $parent->ownerDocument->createElementNS(self::NS_CBC, 'cbc:'.$name);
        $node->appendChild($parent->ownerDocument->createTextNode($value));

        foreach ($attributes as $attribute => $attributeValue) {
            $node->setAttribute($attribute, $attributeValue);
        }

        $parent->appendChild($node);
    }

    private function amount(DOMElement $parent, string $name, string $value, string $currency): void
    {
        $this->cbc($parent, $name, $value, ['currencyID' => $currency]);
    }

    /**
     * A decimal without the trailing zeros of the intermediate scale, with at least two decimals.
     */
    private function trimDecimal(string $value): string
    {
        $trimmed = rtrim(rtrim(Decimal::normalize($value), '0'), '.');
        $decimals = str_contains($trimmed, '.') ? strlen(substr($trimmed, (int) strpos($trimmed, '.') + 1)) : 0;

        return $decimals >= 2 ? $trimmed : Decimal::round2($trimmed);
    }

    /**
     * A VAT number with its country prefix, whichever way the record stores it.
     */
    private function vatWithCountry(string $vat, string $country): string
    {
        $clean = strtoupper((string) preg_replace('/[\s.\-]/', '', $vat));

        return preg_match('/^[A-Z]{2}/', $clean) === 1 ? $clean : $country.$clean;
    }

    /**
     * @throws InvalidArgumentException naming what is missing
     */
    private function required(?string $value, string $field): string
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException("Missing master data for the Peppol document: {$field}.");
        }

        return $value;
    }
}
