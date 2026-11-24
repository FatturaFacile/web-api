<?php

declare(strict_types=1);

namespace Modules\SDI\Services;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use DOMDocument;
use DOMElement;
use InvalidArgumentException;
use Modules\SDI\Enums\RegimeFiscale;

/**
 * Simplified FatturaPA (FSM10, schema v1.0.2): one DatiBeniServizi block per VAT group with the gross amount, for
 * documents up to EUR 400 (a rectified one may exceed it, Art. 21-bis DPR 633/72).
 */
final class FatturaSemplificataBuilder
{
    public const string XmlNamespace = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.0';

    public const float Cap = 400.0;

    public function __construct(private readonly FatturaPaBuilderService $ordinary) {}

    /**
     * @param  list<array{name: string, content: string, format?: string, description?: string}>  $attachments
     *
     * @throws InvalidArgumentException when mandatory master data is missing or the cap is exceeded
     */
    public function build(Invoice $invoice, ?string $progressive = null, array $attachments = []): string
    {
        $tenant = $invoice->tenant;
        $contact = $invoice->contact;

        if ($tenant === null || $contact === null) {
            throw new InvalidArgumentException('A simplified invoice needs a tenant and a customer.');
        }

        $type = $this->documentType($invoice);
        $groups = $this->groups($invoice);
        $gross = array_sum(array_map(fn (array $g): float => $g['gross'], $groups));

        if ($type === 'TD07' && $gross > self::Cap + 0.001) {
            throw new InvalidArgumentException('A simplified invoice cannot exceed EUR 400.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;
        $root = $dom->createElementNS(self::XmlNamespace, 'p:FatturaElettronicaSemplificata');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttribute('versione', 'FSM10');
        $dom->appendChild($root);

        $header = $dom->createElement('FatturaElettronicaHeader');
        $root->appendChild($header);
        $header->appendChild($this->transmission($dom, $invoice, $progressive));
        $header->appendChild($this->seller($dom, $tenant));
        $header->appendChild($this->buyer($dom, $contact));

        $body = $dom->createElement('FatturaElettronicaBody');
        $root->appendChild($body);
        $body->appendChild($this->general($dom, $invoice, $type));

        foreach ($groups as $group) {
            $body->appendChild($this->goods($dom, $group));
        }

        foreach ($attachments as $attachment) {
            $body->appendChild($this->attachment($dom, $attachment));
        }

        return (string) $dom->saveXML();
    }

    private function documentType(Invoice $invoice): string
    {
        return $invoice->invoice_type === 'credit_note' ? 'TD08' : 'TD07';
    }

    /**
     * @return list<array{rate: float, nature: ?string, descriptions: list<string>, taxable: float, tax: float, gross: float}>
     */
    private function groups(Invoice $invoice): array
    {
        $groups = [];

        foreach ($invoice->items as $item) {
            $rate = $invoice->reverse_charge ? 0.0 : (float) $item->tax_rate;
            $nature = $this->ordinary->lineNature($invoice, $rate);
            $key = number_format($rate, 2, '.', '').'|'.$nature;
            $groups[$key] ??= ['rate' => $rate, 'nature' => $nature, 'descriptions' => [], 'taxable' => 0.0, 'tax' => 0.0, 'gross' => 0.0];
            $groups[$key]['descriptions'][] = (string) $item->description;
            $groups[$key]['taxable'] += (float) $item->line_total;
        }

        foreach ($groups as &$group) {
            $group['taxable'] = round($group['taxable'], 2);
            $group['tax'] = round($group['taxable'] * $group['rate'] / 100, 2);
            $group['gross'] = round($group['taxable'] + $group['tax'], 2);
        }

        return array_values($groups);
    }

    private function transmission(DOMDocument $dom, Invoice $invoice, ?string $progressive): DOMElement
    {
        $tenant = $invoice->tenant;
        $contact = $invoice->contact;
        $element = $dom->createElement('DatiTrasmissione');
        $id = $dom->createElement('IdTrasmittente');
        $id->appendChild($dom->createElement('IdPaese', 'IT'));
        $id->appendChild($dom->createElement('IdCodice', $this->required($this->fiscalId($tenant->vat_number), 'tenant vat_number')));
        $element->appendChild($id);
        $element->appendChild($dom->createElement('ProgressivoInvio', $progressive ?? '00001'));
        $element->appendChild($dom->createElement('FormatoTrasmissione', 'FSM10'));

        $foreign = strtoupper((string) $contact->country) !== 'IT';
        $code = $foreign ? 'XXXXXXX' : strtoupper((string) ($contact->sdi_code ?: '0000000'));
        $element->appendChild($dom->createElement('CodiceDestinatario', $code));

        if ($code === '0000000' && ! empty($contact->pec)) {
            $element->appendChild($dom->createElement('PECDestinatario', (string) $contact->pec));
        }

        return $element;
    }

    private function seller(DOMDocument $dom, Tenant $tenant): DOMElement
    {
        $seller = $dom->createElement('CedentePrestatore');
        $seller->appendChild($this->identity($dom, $tenant->country ?: 'IT', $this->required($this->fiscalId($tenant->vat_number), 'tenant vat_number')));

        if (! empty($tenant->fiscal_code)) {
            $seller->appendChild($dom->createElement('CodiceFiscale', (string) $tenant->fiscal_code));
        }

        $seller->appendChild($dom->createElement('Denominazione', htmlspecialchars((string) ($tenant->legal_name ?: $tenant->name))));
        $address = $this->ordinary->tenantAddress($tenant);
        $seller->appendChild($this->seat(
            $dom,
            $this->required($address['street'], 'tenant street'),
            $this->required($address['postal_code'], 'tenant postal_code'),
            $this->required($address['city'], 'tenant city'),
            $address['province'],
            $tenant->country ?: 'IT',
        ));
        $seller->appendChild($dom->createElement('RegimeFiscale', RegimeFiscale::ofTenant($tenant)->value));

        return $seller;
    }

    private function buyer(DOMDocument $dom, Contact $contact): DOMElement
    {
        $buyer = $dom->createElement('CessionarioCommittente');
        $fiscal = $dom->createElement('IdentificativiFiscali');

        if (! empty($contact->vat_number)) {
            $fiscal->appendChild($this->identity($dom, $contact->country ?: 'IT', $this->fiscalId($contact->vat_number)));
        }

        if (! empty($contact->fiscal_code)) {
            $fiscal->appendChild($dom->createElement('CodiceFiscale', (string) $contact->fiscal_code));
        }

        $buyer->appendChild($fiscal);
        $other = $dom->createElement('AltriDatiIdentificativi');

        if (! empty($contact->company_name)) {
            $other->appendChild($dom->createElement('Denominazione', htmlspecialchars((string) $contact->company_name)));
        } else {
            $other->appendChild($dom->createElement('Nome', htmlspecialchars((string) $contact->first_name)));
            $other->appendChild($dom->createElement('Cognome', htmlspecialchars((string) $contact->last_name)));
        }

        $address = $contact->addresses()->where('type', 'billing')->first();
        $other->appendChild($this->seat(
            $dom,
            $this->required($address?->street, 'contact billing street'),
            $this->required($address?->postal_code, 'contact billing postal_code'),
            $this->required($address?->city, 'contact billing city'),
            $address?->state_canton,
            $contact->country ?: 'IT',
        ));
        $buyer->appendChild($other);

        return $buyer;
    }

    private function general(DOMDocument $dom, Invoice $invoice, string $type): DOMElement
    {
        $general = $dom->createElement('DatiGenerali');
        $document = $dom->createElement('DatiGeneraliDocumento');
        $document->appendChild($dom->createElement('TipoDocumento', $type));
        $document->appendChild($dom->createElement('Divisa', strtoupper((string) ($invoice->currency ?: 'EUR'))));
        $document->appendChild($dom->createElement('Data', $invoice->date->format('Y-m-d')));
        $document->appendChild($dom->createElement('Numero', substr((string) $invoice->invoice_number, 0, 20)));
        $general->appendChild($document);

        return $general;
    }

    /**
     * @param  array{rate: float, nature: ?string, descriptions: list<string>, taxable: float, tax: float, gross: float}  $group
     */
    private function goods(DOMDocument $dom, array $group): DOMElement
    {
        $goods = $dom->createElement('DatiBeniServizi');
        $goods->appendChild($dom->createElement('Descrizione', htmlspecialchars(mb_substr(implode('; ', $group['descriptions']), 0, 1000))));
        $goods->appendChild($dom->createElement('Importo', number_format($group['gross'], 2, '.', '')));
        $vat = $dom->createElement('DatiIVA');

        if ($group['nature'] === null) {
            $vat->appendChild($dom->createElement('Imposta', number_format($group['tax'], 2, '.', '')));
        }

        $vat->appendChild($dom->createElement('Aliquota', number_format($group['rate'], 2, '.', '')));
        $goods->appendChild($vat);

        if ($group['nature'] !== null) {
            $goods->appendChild($dom->createElement('Natura', $group['nature']));
        }

        return $goods;
    }

    /**
     * @param  array{name: string, content: string, format?: string, description?: string}  $attachment
     */
    private function attachment(DOMDocument $dom, array $attachment): DOMElement
    {
        $element = $dom->createElement('Allegati');
        $element->appendChild($dom->createElement('NomeAttachment', htmlspecialchars(mb_substr($attachment['name'], 0, 60))));

        if (isset($attachment['format'])) {
            $element->appendChild($dom->createElement('FormatoAttachment', $attachment['format']));
        }

        if (isset($attachment['description'])) {
            $element->appendChild($dom->createElement('DescrizioneAttachment', htmlspecialchars(mb_substr($attachment['description'], 0, 100))));
        }

        $element->appendChild($dom->createElement('Attachment', base64_encode($attachment['content'])));

        return $element;
    }

    private function identity(DOMDocument $dom, string $country, string $code): DOMElement
    {
        $identity = $dom->createElement('IdFiscaleIVA');
        $identity->appendChild($dom->createElement('IdPaese', $country));
        $identity->appendChild($dom->createElement('IdCodice', $code));

        return $identity;
    }

    private function seat(DOMDocument $dom, string $street, string $postalCode, string $city, ?string $province, string $country): DOMElement
    {
        $seat = $dom->createElement('Sede');
        $seat->appendChild($dom->createElement('Indirizzo', htmlspecialchars($street)));
        $seat->appendChild($dom->createElement('CAP', $country === 'IT' || preg_match('/^\d{5}$/', $postalCode) === 1 ? $postalCode : (string) config('fiscal_it.foreign_postal_code')));
        $seat->appendChild($dom->createElement('Comune', htmlspecialchars($city)));

        if ($country === 'IT' && ! empty($province)) {
            $seat->appendChild($dom->createElement('Provincia', strtoupper(substr($province, 0, 2))));
        }

        $seat->appendChild($dom->createElement('Nazione', $country));

        return $seat;
    }

    private function required(?string $value, string $field): string
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException("Missing master data for the FatturaPA file: {$field}.");
        }

        return $value;
    }

    private function fiscalId(?string $value): string
    {
        return (string) preg_replace('/^[A-Z]{2}(?=\d)/', '', strtoupper(trim((string) $value)));
    }
}
