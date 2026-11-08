<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\Sales\VatSummaryCalculator;
use Carbon\Carbon;
use DOMDocument;
use DOMElement;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\DeclarationOfIntent;
use Modules\Invoicing\Models\InvoiceInstallment;
use Modules\SDI\Enums\CausalePagamento;
use Modules\SDI\Enums\NaturaIva;
use Modules\SDI\Enums\RegimeFiscale;
use Modules\SDI\Enums\TipoDocumento;
use Modules\SDI\Enums\TipoRitenuta;
use Modules\SDI\Services\SdiPreflight;

class FatturaPaBuilderService
{
    public function __construct(private readonly VatSummaryCalculator $vat = new VatSummaryCalculator) {}

    /**
     * Validate an Italian Partita IVA (11 digits with Luhn algorithm variant).
     */
    public function isValidPartitaIva(string $piva): bool
    {
        $clean = preg_replace('/\D/', '', $piva) ?? '';
        if (strlen($clean) !== 11) {
            return false;
        }

        if ($clean === '00000000000') {
            return false;
        }

        $s = 0;
        for ($i = 0; $i <= 9; $i += 2) {
            $s += (int) $clean[$i];
        }

        for ($i = 1; $i <= 9; $i += 2) {
            $c = 2 * (int) $clean[$i];
            $s += ($c > 9) ? ($c - 9) : $c;
        }

        $check = (10 - ($s % 10)) % 10;

        return $check === (int) $clean[10];
    }

    /**
     * Validate an Italian Codice Fiscale (16 characters alphanumeric with control character).
     */
    public function isValidCodiceFiscale(string $cf): bool
    {
        $clean = strtoupper(trim($cf));
        if (strlen($clean) !== 16 || ! preg_match('/^[A-Z0-9]{16}$/', $clean)) {
            return false;
        }

        $oddMap = [
            '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13,
            '6' => 15, '7' => 17, '8' => 19, '9' => 21, 'A' => 1, 'B' => 0,
            'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15, 'H' => 17,
            'I' => 19, 'J' => 21, 'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20,
            'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14,
            'U' => 16, 'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
        ];

        $evenMap = [
            '0' => 0, '1' => 1, '2' => 2, '3' => 3, '4' => 4, '5' => 5,
            '6' => 6, '7' => 7, '8' => 8, '9' => 9, 'A' => 0, 'B' => 1,
            'C' => 2, 'D' => 3, 'E' => 4, 'F' => 5, 'G' => 6, 'H' => 7,
            'I' => 8, 'J' => 9, 'K' => 10, 'L' => 11, 'M' => 12, 'N' => 13,
            'O' => 14, 'P' => 15, 'Q' => 16, 'R' => 17, 'S' => 18, 'T' => 19,
            'U' => 20, 'V' => 21, 'W' => 22, 'X' => 23, 'Y' => 24, 'Z' => 25,
        ];

        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $char = $clean[$i];
            if (($i + 1) % 2 === 1) { // Odd character (1-based index)
                $sum += $oddMap[$char] ?? 0;
            } else {
                $sum += $evenMap[$char] ?? 0;
            }
        }

        $expectedCheckChar = chr(65 + ($sum % 26));

        return $expectedCheckChar === $clean[15];
    }

    /**
     * Validate Italian SDI Recipient Code (7 characters alphanumeric).
     */
    public function isValidSdiCode(string $code): bool
    {
        $clean = strtoupper(trim($code));

        return (bool) preg_match('/^[A-Z0-9]{7}$/', $clean);
    }

    /**
     * Build and serialize the official FatturaPA XML (schema v1.2.3). It never fills in missing master data: the document
     * must have passed {@see SdiPreflight}, and what is still missing here is an error.
     *
     * @throws InvalidArgumentException when a mandatory value is missing
     */
    public function buildFatturaPaXml(Invoice $invoice, ?string $progressive = null): string
    {
        $tenant = $invoice->tenant;
        $contact = $invoice->contact;

        if ($tenant === null) {
            throw new InvalidArgumentException('Invoice must belong to a valid tenant.');
        }

        $type = TipoDocumento::ofInvoice($invoice);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(
            'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2',
            'p:FatturaElettronica'
        );
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:p', 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $formatoTrasmissione = $this->transmissionFormat($invoice);
        $root->setAttribute('versione', $formatoTrasmissione);
        $dom->appendChild($root);

        $header = $dom->createElement('FatturaElettronicaHeader');
        $root->appendChild($header);

        $header->appendChild($this->transmissionData($dom, $invoice, $type, $formatoTrasmissione, $progressive));
        $header->appendChild($type->swapsParties() ? $this->contactAsCedente($dom, $contact) : $this->tenantAsCedente($dom, $tenant));
        $header->appendChild($type->isSelfInvoice() ? $this->tenantAsCessionario($dom, $tenant) : $this->contactAsCessionario($dom, $contact));

        if ($type->swapsParties()) {
            $header->appendChild($dom->createElement('SoggettoEmittente', 'CC'));
        }

        $body = $dom->createElement('FatturaElettronicaBody');
        $root->appendChild($body);

        $body->appendChild($this->generalData($dom, $invoice, $type));
        $body->appendChild($this->goodsAndServices($dom, $invoice));

        if (! $type->isSelfInvoice()) {
            $body->appendChild($this->paymentData($dom, $invoice));
        }

        return (string) $dom->saveXML();
    }

    /**
     * The registered office of the tenant, from its settings or from the `company` preferences the documents print.
     *
     * @return array{street: ?string, postal_code: ?string, city: ?string, province: ?string}
     */
    public function tenantAddress(Tenant $tenant): array
    {
        $settings = $tenant->settings ?? [];
        $company = $settings['preferences']['company'] ?? [];
        $street = $company['street'] ?? null;

        if ($street !== null && ! empty($company['house_number'])) {
            $street .= ' '.$company['house_number'];
        }

        return [
            'street' => $settings['street'] ?? $street,
            'postal_code' => $settings['postal_code'] ?? ($company['postal_code'] ?? null),
            'city' => $settings['city'] ?? ($company['city'] ?? null),
            'province' => $settings['province'] ?? ($company['province'] ?? null),
        ];
    }

    /**
     * FPA12 for a public administration, recognised by its six-character office code. A self-invoice is addressed to the
     * tenant itself, so the code of the counterparty does not count.
     */
    public function transmissionFormat(Invoice $invoice): string
    {
        $contact = $invoice->contact;

        if (TipoDocumento::ofInvoice($invoice)->isSelfInvoice()) {
            return 'FPR12';
        }

        return ($contact?->sdi_code && strlen(trim($contact->sdi_code)) === 6) ? 'FPA12' : 'FPR12';
    }

    /**
     * VAT nature of a line, null when it carries VAT. A nature next to a rate is refused by SDI (error 00401).
     */
    public function lineNature(Invoice $invoice, float $rate): ?string
    {
        if ($invoice->reverse_charge) {
            return 'N6.9'; // Inversione contabile - altri casi
        }

        if ($rate !== 0.0) {
            return null;
        }

        if (RegimeFiscale::ofTenant($invoice->tenant)->isForfettario()) {
            return NaturaIva::N22->value;
        }

        if ($invoice->declaration_of_intent_id !== null) {
            return NaturaIva::N35->value;
        }

        return $invoice->vat_nature ?? ($invoice->tenant->settings['sdi_zero_rate_nature'] ?? null);
    }

    private function transmissionData(DOMDocument $dom, Invoice $invoice, TipoDocumento $type, string $format, ?string $progressive): DOMElement
    {
        $tenant = $invoice->tenant;
        $contact = $invoice->contact;

        $datiTrasmissione = $dom->createElement('DatiTrasmissione');
        $idTrasmittente = $dom->createElement('IdTrasmittente');
        $idTrasmittente->appendChild($dom->createElement('IdPaese', 'IT'));
        $idTrasmittente->appendChild($dom->createElement('IdCodice', $this->required($this->fiscalId($tenant->vat_number), 'tenant vat_number')));
        $datiTrasmissione->appendChild($idTrasmittente);

        $progressivoInvio = $progressive ?? (substr(preg_replace('/[^A-Za-z0-9]/', '', $invoice->invoice_number), 0, 10) ?: 'INV'.time());
        $datiTrasmissione->appendChild($dom->createElement('ProgressivoInvio', $progressivoInvio));
        $datiTrasmissione->appendChild($dom->createElement('FormatoTrasmissione', $format));

        // A self-invoice comes back to the tenant, not to the supplier it integrates.
        $sdiCode = $type->isSelfInvoice() ? $tenant->sdi_code : $contact->sdi_code;
        $pec = $type->isSelfInvoice() ? $tenant->pec_email : $contact->pec;
        $codiceDestinatario = $sdiCode ?: '0000000';
        $datiTrasmissione->appendChild($dom->createElement('CodiceDestinatario', strtoupper($codiceDestinatario)));

        if ($codiceDestinatario === '0000000' && ! empty($pec)) {
            $datiTrasmissione->appendChild($dom->createElement('PECDestinatario', $pec));
        }

        return $datiTrasmissione;
    }

    private function tenantAsCedente(DOMDocument $dom, Tenant $tenant): DOMElement
    {
        $cedente = $dom->createElement('CedentePrestatore');
        $dati = $dom->createElement('DatiAnagrafici');
        $dati->appendChild($this->fiscalIdentity($dom, $tenant->country ?: 'IT', $this->required($this->fiscalId($tenant->vat_number), 'tenant vat_number')));

        if (! empty($tenant->fiscal_code)) {
            $dati->appendChild($dom->createElement('CodiceFiscale', $tenant->fiscal_code));
        }

        $anagrafica = $dom->createElement('Anagrafica');
        $anagrafica->appendChild($dom->createElement('Denominazione', htmlspecialchars($tenant->legal_name ?: $tenant->name)));
        $dati->appendChild($anagrafica);
        $dati->appendChild($dom->createElement('RegimeFiscale', RegimeFiscale::ofTenant($tenant)->value));
        $cedente->appendChild($dati);
        $cedente->appendChild($this->tenantSeat($dom, $tenant));

        return $cedente;
    }

    private function tenantAsCessionario(DOMDocument $dom, Tenant $tenant): DOMElement
    {
        $cessionario = $dom->createElement('CessionarioCommittente');
        $dati = $dom->createElement('DatiAnagrafici');
        $dati->appendChild($this->fiscalIdentity($dom, $tenant->country ?: 'IT', $this->required($this->fiscalId($tenant->vat_number), 'tenant vat_number')));

        if (! empty($tenant->fiscal_code)) {
            $dati->appendChild($dom->createElement('CodiceFiscale', $tenant->fiscal_code));
        }

        $anagrafica = $dom->createElement('Anagrafica');
        $anagrafica->appendChild($dom->createElement('Denominazione', htmlspecialchars($tenant->legal_name ?: $tenant->name)));
        $dati->appendChild($anagrafica);
        $cessionario->appendChild($dati);
        $cessionario->appendChild($this->tenantSeat($dom, $tenant));

        return $cessionario;
    }

    private function tenantSeat(DOMDocument $dom, Tenant $tenant): DOMElement
    {
        $address = $this->tenantAddress($tenant);

        return $this->seat(
            $dom,
            $this->required($address['street'], 'tenant street'),
            $this->required($address['postal_code'], 'tenant postal_code'),
            $this->required($address['city'], 'tenant city'),
            $address['province'],
            $tenant->country ?: 'IT'
        );
    }

    /**
     * The supplier of an integration document: what the buyer knows of it, under a regime it cannot state.
     */
    private function contactAsCedente(DOMDocument $dom, Contact $contact): DOMElement
    {
        $cedente = $dom->createElement('CedentePrestatore');
        $dati = $dom->createElement('DatiAnagrafici');
        $dati->appendChild($this->fiscalIdentity($dom, $contact->country ?: 'IT', $this->required($this->fiscalId($contact->vat_number), 'supplier vat_number')));

        if (! empty($contact->fiscal_code)) {
            $dati->appendChild($dom->createElement('CodiceFiscale', $contact->fiscal_code));
        }

        $dati->appendChild($this->contactRegistry($dom, $contact));
        $dati->appendChild($dom->createElement('RegimeFiscale', (string) config('fiscal_it.self_invoice.supplier_regime')));
        $cedente->appendChild($dati);
        $cedente->appendChild($this->contactSeat($dom, $contact));

        return $cedente;
    }

    private function contactAsCessionario(DOMDocument $dom, Contact $contact): DOMElement
    {
        $cessionario = $dom->createElement('CessionarioCommittente');
        $dati = $dom->createElement('DatiAnagrafici');

        if (! empty($contact->vat_number)) {
            $dati->appendChild($this->fiscalIdentity($dom, $contact->country ?: 'IT', $this->fiscalId($contact->vat_number)));
        }

        if (! empty($contact->fiscal_code)) {
            $dati->appendChild($dom->createElement('CodiceFiscale', $contact->fiscal_code));
        }

        $dati->appendChild($this->contactRegistry($dom, $contact));
        $cessionario->appendChild($dati);
        $cessionario->appendChild($this->contactSeat($dom, $contact));

        return $cessionario;
    }

    private function contactRegistry(DOMDocument $dom, Contact $contact): DOMElement
    {
        $anagrafica = $dom->createElement('Anagrafica');

        if (! empty($contact->company_name)) {
            $anagrafica->appendChild($dom->createElement('Denominazione', htmlspecialchars($contact->company_name)));
        } else {
            $anagrafica->appendChild($dom->createElement('Nome', htmlspecialchars($contact->first_name ?: '')));
            $anagrafica->appendChild($dom->createElement('Cognome', htmlspecialchars($contact->last_name ?: '')));
        }

        return $anagrafica;
    }

    private function contactSeat(DOMDocument $dom, Contact $contact): DOMElement
    {
        $billingAddress = $contact->addresses()->where('type', 'billing')->first();

        return $this->seat(
            $dom,
            $this->required($billingAddress?->street, 'contact billing street'),
            $this->required($billingAddress?->postal_code, 'contact billing postal_code'),
            $this->required($billingAddress?->city, 'contact billing city'),
            $billingAddress?->state_canton,
            $contact->country ?: 'IT'
        );
    }

    private function fiscalIdentity(DOMDocument $dom, string $country, string $code): DOMElement
    {
        $identity = $dom->createElement('IdFiscaleIVA');
        $identity->appendChild($dom->createElement('IdPaese', $country));
        $identity->appendChild($dom->createElement('IdCodice', $code));

        return $identity;
    }

    private function seat(DOMDocument $dom, string $street, string $postalCode, string $city, ?string $province, string $country): DOMElement
    {
        $isItalian = $country === 'IT';
        $sede = $dom->createElement('Sede');
        $sede->appendChild($dom->createElement('Indirizzo', htmlspecialchars($street)));
        $sede->appendChild($dom->createElement('CAP', $isItalian || preg_match('/^\d{5}$/', $postalCode) === 1 ? $postalCode : (string) config('fiscal_it.foreign_postal_code')));
        $sede->appendChild($dom->createElement('Comune', htmlspecialchars($city)));

        if ($isItalian && ! empty($province)) {
            $sede->appendChild($dom->createElement('Provincia', strtoupper(substr($province, 0, 2))));
        }

        $sede->appendChild($dom->createElement('Nazione', $country));

        return $sede;
    }

    private function generalData(DOMDocument $dom, Invoice $invoice, TipoDocumento $type): DOMElement
    {
        $datiGenerali = $dom->createElement('DatiGenerali');
        $datiGeneraliDoc = $dom->createElement('DatiGeneraliDocumento');
        $datiGeneraliDoc->appendChild($dom->createElement('TipoDocumento', $type->value));
        $datiGeneraliDoc->appendChild($dom->createElement('Divisa', $invoice->currency ?: 'EUR'));
        $datiGeneraliDoc->appendChild($dom->createElement('Data', $invoice->date->format('Y-m-d')));
        $datiGeneraliDoc->appendChild($dom->createElement('Numero', $invoice->invoice_number));

        foreach ($this->withholdingBlocks($dom, $invoice) as $block) {
            $datiGeneraliDoc->appendChild($block);
        }

        if ($invoice->stamp_duty_applied) {
            $datiBollo = $dom->createElement('DatiBollo');
            $datiBollo->appendChild($dom->createElement('BolloVirtuale', 'SI'));
            $datiBollo->appendChild($dom->createElement('ImportoBollo', $this->money($invoice->stamp_duty_amount)));
            $datiGeneraliDoc->appendChild($datiBollo);
        }

        if ($invoice->fund_enabled) {
            $datiGeneraliDoc->appendChild($this->pensionFund($dom, $invoice));
        }

        $datiGeneraliDoc->appendChild($dom->createElement('ImportoTotaleDocumento', $this->money($invoice->total_gross)));

        foreach ($this->legalWordings($invoice) as $wording) {
            $datiGeneraliDoc->appendChild($dom->createElement('Causale', htmlspecialchars($wording)));
        }

        $datiGenerali->appendChild($datiGeneraliDoc);

        $linked = $this->linkedDocument($dom, $invoice);

        if ($linked !== null) {
            $datiGenerali->appendChild($linked);
        }

        foreach ($this->transportDocuments($dom, $invoice) as $transportDocument) {
            $datiGenerali->appendChild($transportDocument);
        }

        return $datiGenerali;
    }

    /**
     * @return list<DOMElement>
     */
    private function withholdingBlocks(DOMDocument $dom, Invoice $invoice): array
    {
        $tenant = $invoice->tenant;
        $blocks = [];

        if ($invoice->withholding_tax_enabled) {
            $blocks[] = $this->withholdingBlock(
                $dom,
                $invoice->withholding_tax_type ?? ($tenant->settings['sdi_withholding_type'] ?? TipoRitenuta::Rt01->value),
                $invoice->withholding_tax_amount,
                $invoice->withholding_tax_percent,
                $invoice->withholding_tax_reason ?? ($tenant->settings['sdi_withholding_reason'] ?? CausalePagamento::A->value)
            );
        }

        if ($invoice->enasarco_enabled && Decimal::isPositive((string) $invoice->enasarco_amount)) {
            $blocks[] = $this->withholdingBlock(
                $dom,
                TipoRitenuta::Rt04->value,
                $invoice->enasarco_amount,
                $invoice->enasarco_percent,
                ($invoice->enasarco_agent_type === 'single_mandate' ? CausalePagamento::Q : CausalePagamento::R)->value
            );
        }

        return $blocks;
    }

    private function withholdingBlock(DOMDocument $dom, string $type, mixed $amount, mixed $rate, string $reason): DOMElement
    {
        $datiRitenuta = $dom->createElement('DatiRitenuta');
        $datiRitenuta->appendChild($dom->createElement('TipoRitenuta', $type));
        $datiRitenuta->appendChild($dom->createElement('ImportoRitenuta', $this->money($amount)));
        $datiRitenuta->appendChild($dom->createElement('AliquotaRitenuta', $this->money($rate)));
        $datiRitenuta->appendChild($dom->createElement('CausalePagamento', $reason));

        return $datiRitenuta;
    }

    private function pensionFund(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $rate = (float) $invoice->fund_vat_rate;
        $nature = $this->lineNature($invoice, $invoice->reverse_charge ? 0.0 : $rate);

        $fund = $dom->createElement('DatiCassaPrevidenziale');
        $fund->appendChild($dom->createElement('TipoCassa', (string) $invoice->fund_type));
        $fund->appendChild($dom->createElement('AlCassa', $this->money($invoice->fund_percent)));
        $fund->appendChild($dom->createElement('ImportoContributoCassa', $this->money($invoice->fund_amount)));
        $fund->appendChild($dom->createElement('ImponibileCassa', $this->money($invoice->total_net)));
        $fund->appendChild($dom->createElement('AliquotaIVA', $this->money($invoice->reverse_charge ? 0 : $rate)));

        if ($invoice->fund_subject_to_withholding && $invoice->withholding_tax_enabled) {
            $fund->appendChild($dom->createElement('Ritenuta', 'SI'));
        }

        if ($nature !== null) {
            $fund->appendChild($dom->createElement('Natura', $nature));
        }

        return $fund;
    }

    /**
     * The wording the law wants on the document. It stays in Italian whatever the language of the customer: the XML goes
     * to the tax authority.
     *
     * @return list<string>
     */
    private function legalWordings(Invoice $invoice): array
    {
        $wordings = [];

        if (RegimeFiscale::ofTenant($invoice->tenant)->isForfettario()) {
            $wordings[] = (string) __('pdf.legal.forfettario', [], 'it');
        }

        $declaration = $invoice->declarationOfIntent;

        if ($declaration !== null) {
            $wordings[] = (string) __('pdf.legal.declaration_of_intent', [
                'protocol' => $declaration->protocol_number,
                'date' => $declaration->receipt_date->format('d/m/Y'),
            ], 'it');
        }

        return $wordings;
    }

    /**
     * The document this one refers to: the received invoice of a self-invoice, the invoice a credit note corrects, or the
     * reference stated by hand.
     */
    private function linkedDocument(DOMDocument $dom, Invoice $invoice): ?DOMElement
    {
        $number = $invoice->linked_document_number;
        $date = $invoice->linked_document_date;

        if ($number === null && $invoice->source_passive_invoice_id !== null) {
            $source = $invoice->sourcePassiveInvoice;
            $number = $source?->invoice_number;
            $date = $source?->date;
        }

        if ($number === null && $invoice->related_invoice_id !== null) {
            $related = $invoice->relatedInvoice;
            $number = $related?->invoice_number;
            $date = $related?->date;
        }

        if ($number === null) {
            return null;
        }

        $linked = $dom->createElement('DatiFattureCollegate');
        $linked->appendChild($dom->createElement('IdDocumento', htmlspecialchars(mb_substr((string) $number, 0, 20))));

        if ($date !== null) {
            $linked->appendChild($dom->createElement('Data', Carbon::parse($date)->format('Y-m-d')));
        }

        return $linked;
    }

    /**
     * DatiDDT: the delivery notes billed by the lines of the invoice (a deferred invoice lists every note it collects),
     * each with its number, date and the numbers of the invoice lines (in the order of DettaglioLinee) that bill it.
     *
     * @return list<DOMElement>
     */
    private function transportDocuments(DOMDocument $dom, Invoice $invoice): array
    {
        $lineNumbers = [];

        foreach ($invoice->items->values() as $index => $item) {
            $noteItemId = $item->getAttribute('delivery_note_item_id');

            if ($noteItemId !== null && $noteItemId !== '') {
                $lineNumbers[(string) $noteItemId][] = $index + 1;
            }
        }

        if ($lineNumbers === []) {
            return [];
        }

        $notes = DB::table('delivery_note_items')
            ->join('delivery_notes', 'delivery_notes.id', '=', 'delivery_note_items.delivery_note_id')
            ->where('delivery_notes.tenant_id', $invoice->tenant_id)
            ->whereIn('delivery_note_items.id', array_keys($lineNumbers))
            ->orderBy('delivery_notes.date')
            ->orderBy('delivery_notes.ddt_number')
            ->get(['delivery_note_items.id as item_id', 'delivery_notes.id as note_id', 'delivery_notes.ddt_number', 'delivery_notes.date']);
        $byNote = [];

        foreach ($notes as $row) {
            $byNote[(string) $row->note_id] ??= ['number' => (string) $row->ddt_number, 'date' => (string) $row->date, 'lines' => []];
            array_push($byNote[(string) $row->note_id]['lines'], ...$lineNumbers[(string) $row->item_id]);
        }

        $elements = [];

        foreach ($byNote as $note) {
            $element = $dom->createElement('DatiDDT');
            $element->appendChild($dom->createElement('NumeroDDT', htmlspecialchars(mb_substr($note['number'], 0, 20))));
            $element->appendChild($dom->createElement('DataDDT', Carbon::parse($note['date'])->format('Y-m-d')));
            sort($note['lines']);

            foreach (array_unique($note['lines']) as $lineNumber) {
                $element->appendChild($dom->createElement('RiferimentoNumeroLinea', (string) $lineNumber));
            }

            $elements[] = $element;
        }

        return $elements;
    }

    private function goodsAndServices(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $datiBeniServizi = $dom->createElement('DatiBeniServizi');
        $declaration = $invoice->declarationOfIntent;
        $lineNumber = 1;
        $vatAggregates = [];
        $hasWithholding = $invoice->withholding_tax_enabled || ($invoice->enasarco_enabled && Decimal::isPositive((string) $invoice->enasarco_amount));

        foreach ($invoice->items as $item) {
            $rate = $invoice->reverse_charge ? 0.0 : (float) $item->tax_rate;
            $natura = $this->lineNature($invoice, $rate);

            $dettaglioLinee = $dom->createElement('DettaglioLinee');
            $dettaglioLinee->appendChild($dom->createElement('NumeroLinea', (string) $lineNumber++));
            $dettaglioLinee->appendChild($dom->createElement('Descrizione', htmlspecialchars(substr($item->description, 0, 1000))));
            $dettaglioLinee->appendChild($dom->createElement('Quantita', number_format((float) $item->quantity, 2, '.', '')));
            $dettaglioLinee->appendChild($dom->createElement('PrezzoUnitario', number_format((float) $item->unit_price, 4, '.', '')));
            $dettaglioLinee->appendChild($dom->createElement('PrezzoTotale', $this->money($item->line_total)));
            $dettaglioLinee->appendChild($dom->createElement('AliquotaIVA', number_format($rate, 2, '.', '')));

            if ($hasWithholding) {
                $dettaglioLinee->appendChild($dom->createElement('Ritenuta', 'SI'));
            }

            if ($natura !== null) {
                $dettaglioLinee->appendChild($dom->createElement('Natura', $natura));
            }

            if ($declaration !== null && $natura === NaturaIva::N35->value) {
                $dettaglioLinee->appendChild($this->declarationReference($dom, $declaration));
            }

            $datiBeniServizi->appendChild($dettaglioLinee);
            $this->addToSummary($vatAggregates, $rate, $natura, (string) $item->line_total);
        }

        if ($invoice->fund_enabled) {
            $rate = $invoice->reverse_charge ? 0.0 : (float) $invoice->fund_vat_rate;
            $this->addToSummary($vatAggregates, $rate, $this->lineNature($invoice, $rate), (string) $invoice->fund_amount);
        }

        foreach ($vatAggregates as $aggregate) {
            $datiBeniServizi->appendChild($this->vatSummary($dom, $invoice, $aggregate));
        }

        return $datiBeniServizi;
    }

    /**
     * @param  array<string, array{rate: float, nature: ?string, taxable: string}>  $aggregates
     */
    private function addToSummary(array &$aggregates, float $rate, ?string $nature, string $amount): void
    {
        $key = number_format($rate, 2, '.', '').'|'.$nature;
        $current = $aggregates[$key]['taxable'] ?? '0';

        $aggregates[$key] = ['rate' => $rate, 'nature' => $nature, 'taxable' => Decimal::add($current, $amount)];
    }

    private function declarationReference(DOMDocument $dom, DeclarationOfIntent $declaration): DOMElement
    {
        $reference = $dom->createElement('AltriDatiGestionali');
        $reference->appendChild($dom->createElement('TipoDato', (string) config('fiscal_it.declaration_of_intent.data_type')));
        $reference->appendChild($dom->createElement('RiferimentoTesto', $declaration->protocol_number));
        $reference->appendChild($dom->createElement('RiferimentoData', $declaration->receipt_date->format('Y-m-d')));

        return $reference;
    }

    /**
     * @param  array{rate: float, nature: ?string, taxable: string}  $aggregate
     */
    private function vatSummary(DOMDocument $dom, Invoice $invoice, array $aggregate): DOMElement
    {
        $rate = number_format($aggregate['rate'], 2, '.', '');
        $tax = $this->vat->taxOn($aggregate['taxable'], $rate);
        $reference = config('fiscal_it.legal_references')[$aggregate['nature'] ?? ''] ?? null;

        $datiRiepilogo = $dom->createElement('DatiRiepilogo');
        $datiRiepilogo->appendChild($dom->createElement('AliquotaIVA', $rate));

        if ($aggregate['nature'] !== null) {
            $datiRiepilogo->appendChild($dom->createElement('Natura', $aggregate['nature']));
        }

        $datiRiepilogo->appendChild($dom->createElement('ImponibileImporto', Decimal::round2($aggregate['taxable'])));
        $datiRiepilogo->appendChild($dom->createElement('Imposta', $tax));

        if ($invoice->split_payment) {
            $datiRiepilogo->appendChild($dom->createElement('EsigibilitaIVA', 'S'));
        } elseif ($aggregate['nature'] === null) {
            $datiRiepilogo->appendChild($dom->createElement('EsigibilitaIVA', 'I')); // Immediata
        }

        if (is_string($reference)) {
            $datiRiepilogo->appendChild($dom->createElement('RiferimentoNormativo', htmlspecialchars($reference)));
        }

        return $datiRiepilogo;
    }

    private function paymentData(DOMDocument $dom, Invoice $invoice): DOMElement
    {
        $tenant = $invoice->tenant;

        // The payment schedule fixed at emission: several installments are a payment in instalments (TP01), each with its
        // own due date and amount; otherwise the payment is in full (TP02) on the due date of the document.
        $installments = InvoiceInstallment::withoutGlobalScope(TenantScope::class)->where('invoice_id', $invoice->id)->orderBy('sequence')->get();
        $rows = $installments->count() > 1
            ? $installments->map(static fn (InvoiceInstallment $row): array => ['due_date' => $row->due_date->format('Y-m-d'), 'amount' => Decimal::round2((string) $row->amount)])->all()
            : [['due_date' => $invoice->due_date?->format('Y-m-d'), 'amount' => $invoice->amountDue()]];

        $datiPagamento = $dom->createElement('DatiPagamento');
        $datiPagamento->appendChild($dom->createElement('CondizioniPagamento', count($rows) > 1 ? 'TP01' : 'TP02'));

        foreach ($rows as $row) {
            $dettaglioPagamento = $dom->createElement('DettaglioPagamento');
            $dettaglioPagamento->appendChild($dom->createElement('ModalitaPagamento', $this->paymentMode($invoice)));

            if ($row['due_date'] !== null) {
                $dettaglioPagamento->appendChild($dom->createElement('DataScadenzaPagamento', $row['due_date']));
            }

            $dettaglioPagamento->appendChild($dom->createElement('ImportoPagamento', $row['amount']));

            if (! empty($tenant->iban)) {
                $dettaglioPagamento->appendChild($dom->createElement('IBAN', preg_replace('/\s+/', '', $tenant->iban)));
            }

            $datiPagamento->appendChild($dettaglioPagamento);
        }

        return $datiPagamento;
    }

    private function money(mixed $amount): string
    {
        return Decimal::round2(number_format((float) $amount, 4, '.', ''));
    }

    /**
     * @throws InvalidArgumentException naming what is missing
     */
    private function required(?string $value, string $field): string
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException("Missing master data for the FatturaPA file: {$field}.");
        }

        return $value;
    }

    /**
     * A fiscal id without the country prefix some records store: the country travels in IdPaese.
     */
    private function fiscalId(?string $value): string
    {
        return (string) preg_replace('/^[A-Z]{2}(?=\d)/', '', strtoupper(trim((string) $value)));
    }

    /**
     * ModalitaPagamento of the invoice, bank transfer when it does not state a FatturaPA code.
     */
    private function paymentMode(Invoice $invoice): string
    {
        return preg_match('/^MP\d{2}$/', (string) $invoice->payment_method) === 1 ? $invoice->payment_method : 'MP05';
    }
}
