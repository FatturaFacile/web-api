<?php

declare(strict_types=1);

namespace Modules\Esterometro\Services;

use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use Modules\Esterometro\Exceptions\EsterometroException;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Models\SelfInvoiceLine;

/**
 * Builds FatturaPA v1.2.2+ XML for TD16–TD28 self-invoices with Cedente/Cessionario role inversion.
 */
final class SelfInvoiceFatturaPaXmlBuilder
{
    private const NAMESPACE_URI = 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2';

    public function build(SelfInvoice $selfInvoice, Tenant $tenant): string
    {
        $selfInvoice->loadMissing('lines');

        $tenantVat = $this->cleanVat((string) ($tenant->vat_number ?? $tenant->tax_id ?? ''));
        if ($tenantVat === '') {
            throw EsterometroException::missingTenantFiscalData('vat_number');
        }

        $supplierCountry = strtoupper(trim($selfInvoice->supplier_country_code)) ?: 'IT';
        $supplierId = $this->cleanVat((string) ($selfInvoice->supplier_vat_number ?: $selfInvoice->supplier_fiscal_code ?: 'OO99999999999'));

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(self::NAMESPACE_URI, 'p:FatturaElettronica');
        $root->setAttribute('versione', 'FPR12');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $dom->appendChild($root);

        $header = $dom->createElement('FatturaElettronicaHeader');
        $root->appendChild($header);

        $this->appendDatiTrasmissione($dom, $header, $selfInvoice, $tenantVat);
        $this->appendCedentePrestatore($dom, $header, $selfInvoice, $supplierCountry, $supplierId);
        $this->appendCessionarioCommittente($dom, $header, $tenant, $tenantVat);

        $soggetto = $dom->createElement('SoggettoEmittente', $selfInvoice->soggetto_emittente ?: 'CC');
        $header->appendChild($soggetto);

        $body = $dom->createElement('FatturaElettronicaBody');
        $root->appendChild($body);

        $this->appendDatiGenerali($dom, $body, $selfInvoice);
        $this->appendDatiBeniServizi($dom, $body, $selfInvoice);

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw EsterometroException::xmlValidationFailed('DOMDocument::saveXML failed');
        }

        return $xml;
    }

    private function appendDatiTrasmissione(
        DOMDocument $dom,
        DOMElement $header,
        SelfInvoice $selfInvoice,
        string $tenantVat,
    ): void {
        $dt = $dom->createElement('DatiTrasmissione');
        $header->appendChild($dt);

        $idTrasm = $dom->createElement('IdTrasmittente');
        $idTrasm->appendChild($dom->createElement('IdPaese', 'IT'));
        $idTrasm->appendChild($dom->createElement('IdCodice', $tenantVat));
        $dt->appendChild($idTrasm);

        $progressive = $selfInvoice->sdi_progressive ?: str_pad((string) $selfInvoice->sequential_number, 5, '0', STR_PAD_LEFT);
        $dt->appendChild($dom->createElement('ProgressivoInvio', substr($progressive, 0, 10)));
        $dt->appendChild($dom->createElement('FormatoTrasmissione', 'FPR12'));
        $dt->appendChild($dom->createElement('CodiceDestinatario', $selfInvoice->recipient_code ?: '0000000'));
    }

    private function appendCedentePrestatore(
        DOMDocument $dom,
        DOMElement $header,
        SelfInvoice $selfInvoice,
        string $supplierCountry,
        string $supplierId,
    ): void {
        $cedente = $dom->createElement('CedentePrestatore');
        $header->appendChild($cedente);

        $datiAnagrafici = $dom->createElement('DatiAnagrafici');
        $cedente->appendChild($datiAnagrafici);

        $idFiscale = $dom->createElement('IdFiscaleIVA');
        $idFiscale->appendChild($dom->createElement('IdPaese', $supplierCountry));
        $idFiscale->appendChild($dom->createElement('IdCodice', substr($supplierId, 0, 28)));
        $datiAnagrafici->appendChild($idFiscale);

        if ($supplierCountry === 'IT' && $selfInvoice->supplier_fiscal_code !== null && $selfInvoice->supplier_fiscal_code !== '') {
            $datiAnagrafici->appendChild($dom->createElement('CodiceFiscale', strtoupper(trim($selfInvoice->supplier_fiscal_code))));
        }

        $anagrafica = $dom->createElement('Anagrafica');
        $anagrafica->appendChild($this->safeTextElement($dom, 'Denominazione', $selfInvoice->supplier_name, 80));
        $datiAnagrafici->appendChild($anagrafica);
        $datiAnagrafici->appendChild($dom->createElement('RegimeFiscale', 'RF01'));

        $addr = $selfInvoice->supplier_address ?? [];
        $sede = $dom->createElement('Sede');
        $sede->appendChild($this->safeTextElement($dom, 'Indirizzo', (string) ($addr['street'] ?? 'N/D'), 60));
        $sede->appendChild($this->safeTextElement($dom, 'CAP', $this->normalizeCap((string) ($addr['postal_code'] ?? '00000'), $supplierCountry), 5));
        $sede->appendChild($this->safeTextElement($dom, 'Comune', (string) ($addr['city'] ?? 'Estero'), 60));
        if (! empty($addr['province'])) {
            $sede->appendChild($this->safeTextElement($dom, 'Provincia', strtoupper((string) $addr['province']), 2));
        }
        $sede->appendChild($dom->createElement('Nazione', $supplierCountry));
        $cedente->appendChild($sede);
    }

    private function appendCessionarioCommittente(
        DOMDocument $dom,
        DOMElement $header,
        Tenant $tenant,
        string $tenantVat,
    ): void {
        $cessionario = $dom->createElement('CessionarioCommittente');
        $header->appendChild($cessionario);

        $datiAnagrafici = $dom->createElement('DatiAnagrafici');
        $cessionario->appendChild($datiAnagrafici);

        $idFiscale = $dom->createElement('IdFiscaleIVA');
        $idFiscale->appendChild($dom->createElement('IdPaese', 'IT'));
        $idFiscale->appendChild($dom->createElement('IdCodice', $tenantVat));
        $datiAnagrafici->appendChild($idFiscale);

        $cf = strtoupper(trim((string) ($tenant->fiscal_code ?? $tenantVat)));
        if ($cf !== '') {
            $datiAnagrafici->appendChild($dom->createElement('CodiceFiscale', substr($cf, 0, 16)));
        }

        $anagrafica = $dom->createElement('Anagrafica');
        $anagrafica->appendChild($this->safeTextElement($dom, 'Denominazione', (string) ($tenant->company_name ?? $tenant->name ?? 'Azienda'), 80));
        $datiAnagrafici->appendChild($anagrafica);

        $sede = $dom->createElement('Sede');
        $sede->appendChild($this->safeTextElement($dom, 'Indirizzo', (string) ($tenant->address ?? 'Via Roma 1'), 60));
        $sede->appendChild($this->safeTextElement($dom, 'CAP', $this->normalizeCap((string) ($tenant->postal_code ?? '00100'), 'IT'), 5));
        $sede->appendChild($this->safeTextElement($dom, 'Comune', (string) ($tenant->city ?? 'Roma'), 60));
        if (! empty($tenant->province)) {
            $sede->appendChild($this->safeTextElement($dom, 'Provincia', strtoupper((string) $tenant->province), 2));
        }
        $sede->appendChild($dom->createElement('Nazione', 'IT'));
        $cessionario->appendChild($sede);
    }

    private function appendDatiGenerali(DOMDocument $dom, DOMElement $body, SelfInvoice $selfInvoice): void
    {
        $dg = $dom->createElement('DatiGenerali');
        $body->appendChild($dg);

        $dgd = $dom->createElement('DatiGeneraliDocumento');
        $dgd->appendChild($dom->createElement('TipoDocumento', $selfInvoice->document_type->value));
        $dgd->appendChild($dom->createElement('Divisa', 'EUR'));
        $dgd->appendChild($dom->createElement('Data', $selfInvoice->integration_date->toDateString()));
        $dgd->appendChild($this->safeTextElement($dom, 'Numero', $selfInvoice->document_number, 20));
        $dgd->appendChild($dom->createElement('ImportoTotaleDocumento', $this->fmt((float) $selfInvoice->gross_amount_eur)));
        $dg->appendChild($dgd);

        if ($selfInvoice->original_invoice_number !== null && $selfInvoice->original_invoice_number !== '') {
            $linked = $dom->createElement('DatiFattureCollegate');
            $linked->appendChild($this->safeTextElement($dom, 'IdDocumento', $selfInvoice->original_invoice_number, 20));
            if ($selfInvoice->original_invoice_date !== null) {
                $linked->appendChild($dom->createElement('Data', $selfInvoice->original_invoice_date->toDateString()));
            }
            if ($selfInvoice->original_sdi_identifier !== null && $selfInvoice->original_sdi_identifier !== '') {
                $linked->appendChild($this->safeTextElement($dom, 'IdSDI', $selfInvoice->original_sdi_identifier, 20));
            }
            $dg->appendChild($linked);
        }
    }

    private function appendDatiBeniServizi(DOMDocument $dom, DOMElement $body, SelfInvoice $selfInvoice): void
    {
        $dbs = $dom->createElement('DatiBeniServizi');
        $body->appendChild($dbs);

        /** @var array<string, array{rate: float, nature: string|null, taxable: float, vat: float}> $summary */
        $summary = [];

        /** @var SelfInvoiceLine $line */
        foreach ($selfInvoice->lines as $index => $line) {
            $dettaglio = $dom->createElement('DettaglioLinee');
            $dettaglio->appendChild($dom->createElement('NumeroLinea', (string) ($line->line_number ?: ($index + 1))));
            $dettaglio->appendChild($this->safeTextElement($dom, 'Descrizione', $line->description, 1000));
            $dettaglio->appendChild($dom->createElement('Quantita', $this->fmt((float) $line->quantity, 2)));
            $dettaglio->appendChild($dom->createElement('PrezzoUnitario', $this->fmt((float) $line->unit_price_eur, 2)));
            $dettaglio->appendChild($dom->createElement('PrezzoTotale', $this->fmt((float) $line->taxable_amount_eur, 2)));
            $dettaglio->appendChild($dom->createElement('AliquotaIVA', $this->fmt((float) $line->vat_rate, 2)));

            if ((float) $line->vat_rate === 0.0 && $line->vat_nature !== null && $line->vat_nature !== '') {
                $dettaglio->appendChild($dom->createElement('Natura', $line->vat_nature));
            }

            $dbs->appendChild($dettaglio);

            $key = $this->fmt((float) $line->vat_rate, 2).'|'.($line->vat_nature ?? '');
            if (! isset($summary[$key])) {
                $summary[$key] = [
                    'rate' => (float) $line->vat_rate,
                    'nature' => $line->vat_nature,
                    'taxable' => 0.0,
                    'vat' => 0.0,
                ];
            }
            $summary[$key]['taxable'] += (float) $line->taxable_amount_eur;
            $summary[$key]['vat'] += (float) $line->vat_amount_eur;
        }

        foreach ($summary as $bucket) {
            $riepilogo = $dom->createElement('DatiRiepilogo');
            $riepilogo->appendChild($dom->createElement('AliquotaIVA', $this->fmt($bucket['rate'], 2)));
            if ($bucket['rate'] === 0.0 && $bucket['nature'] !== null && $bucket['nature'] !== '') {
                $riepilogo->appendChild($dom->createElement('Natura', $bucket['nature']));
            }
            $riepilogo->appendChild($dom->createElement('ImponibileImporto', $this->fmt($bucket['taxable'], 2)));
            $riepilogo->appendChild($dom->createElement('Imposta', $this->fmt($bucket['vat'], 2)));
            if ($bucket['rate'] > 0.0) {
                $riepilogo->appendChild($dom->createElement('EsigibilitaIVA', 'I'));
            }
            $dbs->appendChild($riepilogo);
        }
    }

    private function safeTextElement(DOMDocument $dom, string $tag, string $value, int $maxLength): DOMElement
    {
        $clean = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        $truncated = mb_substr($clean !== '' ? $clean : 'N/D', 0, $maxLength);
        $element = $dom->createElement($tag);
        $element->appendChild($dom->createTextNode($truncated));

        return $element;
    }

    private function cleanVat(string $vat): string
    {
        $stripped = preg_replace('/[^A-Z0-9]/i', '', strtoupper($vat)) ?? '';
        if (str_starts_with($stripped, 'IT') && strlen($stripped) > 2) {
            return substr($stripped, 2);
        }

        return $stripped;
    }

    private function normalizeCap(string $cap, string $country): string
    {
        $digits = preg_replace('/[^0-9]/', '', $cap) ?? '';
        if ($country !== 'IT') {
            return '00000';
        }

        return str_pad(substr($digits, 0, 5), 5, '0', STR_PAD_LEFT);
    }

    private function fmt(float $amount, int $decimals = 2): string
    {
        return number_format($amount, $decimals, '.', '');
    }
}
