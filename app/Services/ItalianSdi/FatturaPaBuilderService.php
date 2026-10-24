<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use App\Models\Invoice;
use DOMDocument;
use InvalidArgumentException;

class FatturaPaBuilderService
{
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
     * Build and serialize the official XML FatturaPA (v1.2.2 / v1.2.1) string.
     */
    public function buildFatturaPaXml(Invoice $invoice): string
    {
        $tenant = $invoice->tenant;
        $contact = $invoice->contact;

        if ($tenant === null) {
            throw new InvalidArgumentException('Invoice must belong to a valid tenant.');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $root = $dom->createElementNS(
            'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2',
            'p:FatturaElettronica'
        );
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', 'http://www.w3.org/2000/09/xmldsig#');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:p', 'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

        $isPublicAdmin = ($contact->sdi_code && strlen(trim($contact->sdi_code)) === 6);
        $formatoTrasmissione = $isPublicAdmin ? 'FPA12' : 'FPR12';
        $root->setAttribute('versione', $formatoTrasmissione);
        $dom->appendChild($root);

        // ==========================================
        // 1. FatturaElettronicaHeader
        // ==========================================
        $header = $dom->createElement('FatturaElettronicaHeader');
        $root->appendChild($header);

        // 1.1 DatiTrasmissione
        $datiTrasmissione = $dom->createElement('DatiTrasmissione');
        $idTrasmittente = $dom->createElement('IdTrasmittente');
        $idTrasmittente->appendChild($dom->createElement('IdPaese', 'IT'));
        $idTrasmittente->appendChild($dom->createElement('IdCodice', substr($tenant->vat_number ?: '99999999999', 0, 28)));
        $datiTrasmissione->appendChild($idTrasmittente);

        $progressivoInvio = substr(preg_replace('/[^A-Za-z0-9]/', '', $invoice->invoice_number), 0, 10) ?: 'INV'.time();
        $datiTrasmissione->appendChild($dom->createElement('ProgressivoInvio', $progressivoInvio));
        $datiTrasmissione->appendChild($dom->createElement('FormatoTrasmissione', $formatoTrasmissione));

        $codiceDestinatario = $contact->sdi_code ?: '0000000';
        $datiTrasmissione->appendChild($dom->createElement('CodiceDestinatario', strtoupper($codiceDestinatario)));

        if ($codiceDestinatario === '0000000' && ! empty($contact->pec)) {
            $datiTrasmissione->appendChild($dom->createElement('PECDestinatario', $contact->pec));
        }
        $header->appendChild($datiTrasmissione);

        // 1.2 CedentePrestatore (Supplier / Active Tenant)
        $cedente = $dom->createElement('CedentePrestatore');
        $datiAnagraficiCedente = $dom->createElement('DatiAnagrafici');
        $idFiscaleIvaCedente = $dom->createElement('IdFiscaleIVA');
        $idFiscaleIvaCedente->appendChild($dom->createElement('IdPaese', $tenant->country ?: 'IT'));
        $idFiscaleIvaCedente->appendChild($dom->createElement('IdCodice', $tenant->vat_number ?: '00000000000'));
        $datiAnagraficiCedente->appendChild($idFiscaleIvaCedente);

        if (! empty($tenant->fiscal_code)) {
            $datiAnagraficiCedente->appendChild($dom->createElement('CodiceFiscale', $tenant->fiscal_code));
        }

        $anagraficaCedente = $dom->createElement('Anagrafica');
        $anagraficaCedente->appendChild($dom->createElement('Denominazione', htmlspecialchars($tenant->legal_name ?: $tenant->name)));
        $datiAnagraficiCedente->appendChild($anagraficaCedente);
        $datiAnagraficiCedente->appendChild($dom->createElement('RegimeFiscale', $tenant->settings['regime_fiscale'] ?? 'RF01'));
        $cedente->appendChild($datiAnagraficiCedente);

        $sedeCedente = $dom->createElement('Sede');
        $sedeCedente->appendChild($dom->createElement('Indirizzo', htmlspecialchars($tenant->settings['street'] ?? 'Via Roma 1')));
        $sedeCedente->appendChild($dom->createElement('CAP', $tenant->settings['postal_code'] ?? '20121'));
        $sedeCedente->appendChild($dom->createElement('Comune', htmlspecialchars($tenant->settings['city'] ?? 'Milano')));
        if (! empty($tenant->settings['province'])) {
            $sedeCedente->appendChild($dom->createElement('Provincia', strtoupper(substr($tenant->settings['province'], 0, 2))));
        }
        $sedeCedente->appendChild($dom->createElement('Nazione', $tenant->country ?: 'IT'));
        $cedente->appendChild($sedeCedente);
        $header->appendChild($cedente);

        // 1.3 CessionarioCommittente (Customer / Contact)
        $cessionario = $dom->createElement('CessionarioCommittente');
        $datiAnagraficiCessionario = $dom->createElement('DatiAnagrafici');

        if (! empty($contact->vat_number)) {
            $idFiscaleIvaCess = $dom->createElement('IdFiscaleIVA');
            $idFiscaleIvaCess->appendChild($dom->createElement('IdPaese', $contact->country ?: 'IT'));
            $idFiscaleIvaCess->appendChild($dom->createElement('IdCodice', $contact->vat_number));
            $datiAnagraficiCessionario->appendChild($idFiscaleIvaCess);
        }

        if (! empty($contact->fiscal_code)) {
            $datiAnagraficiCessionario->appendChild($dom->createElement('CodiceFiscale', $contact->fiscal_code));
        }

        $anagraficaCess = $dom->createElement('Anagrafica');
        if (! empty($contact->company_name)) {
            $anagraficaCess->appendChild($dom->createElement('Denominazione', htmlspecialchars($contact->company_name)));
        } else {
            $anagraficaCess->appendChild($dom->createElement('Nome', htmlspecialchars($contact->first_name ?: '')));
            $anagraficaCess->appendChild($dom->createElement('Cognome', htmlspecialchars($contact->last_name ?: '')));
        }
        $datiAnagraficiCessionario->appendChild($anagraficaCess);
        $cessionario->appendChild($datiAnagraficiCessionario);

        $billingAddress = $contact->addresses()->where('type', 'billing')->first();
        $sedeCess = $dom->createElement('Sede');
        $sedeCess->appendChild($dom->createElement('Indirizzo', htmlspecialchars($billingAddress?->street ?: 'Via Dante 10')));
        $sedeCess->appendChild($dom->createElement('CAP', $billingAddress?->postal_code ?: '00100'));
        $sedeCess->appendChild($dom->createElement('Comune', htmlspecialchars($billingAddress?->city ?: 'Roma')));
        if (! empty($billingAddress?->state_canton)) {
            $sedeCess->appendChild($dom->createElement('Provincia', strtoupper(substr($billingAddress->state_canton, 0, 2))));
        }
        $sedeCess->appendChild($dom->createElement('Nazione', $contact->country ?: 'IT'));
        $cessionario->appendChild($sedeCess);
        $header->appendChild($cessionario);

        // ==========================================
        // 2. FatturaElettronicaBody
        // ==========================================
        $body = $dom->createElement('FatturaElettronicaBody');
        $root->appendChild($body);

        // 2.1 DatiGenerali
        $datiGenerali = $dom->createElement('DatiGenerali');
        $datiGeneraliDoc = $dom->createElement('DatiGeneraliDocumento');

        $tipoDocumento = match ($invoice->invoice_type) {
            'credit_note' => 'TD04',
            default => 'TD01',
        };
        $datiGeneraliDoc->appendChild($dom->createElement('TipoDocumento', $tipoDocumento));
        $datiGeneraliDoc->appendChild($dom->createElement('Divisa', $invoice->currency ?: 'EUR'));
        $datiGeneraliDoc->appendChild($dom->createElement('Data', $invoice->date->format('Y-m-d')));
        $datiGeneraliDoc->appendChild($dom->createElement('Numero', $invoice->invoice_number));
        $datiGeneraliDoc->appendChild($dom->createElement('ImportoTotaleDocumento', number_format((float) $invoice->total_gross, 2, '.', '')));
        $datiGenerali->appendChild($datiGeneraliDoc);
        $body->appendChild($datiGenerali);

        // 2.2 DatiBeniServizi
        $datiBeniServizi = $dom->createElement('DatiBeniServizi');
        $lineNumber = 1;
        $vatAggregates = [];

        foreach ($invoice->items as $item) {
            $dettaglioLinee = $dom->createElement('DettaglioLinee');
            $dettaglioLinee->appendChild($dom->createElement('NumeroLinea', (string) $lineNumber++));
            $dettaglioLinee->appendChild($dom->createElement('Descrizione', htmlspecialchars(substr($item->description, 0, 1000))));
            $dettaglioLinee->appendChild($dom->createElement('Quantita', number_format((float) $item->quantity, 2, '.', '')));
            $dettaglioLinee->appendChild($dom->createElement('PrezzoUnitario', number_format((float) $item->unit_price, 4, '.', '')));
            $dettaglioLinee->appendChild($dom->createElement('PrezzoTotale', number_format((float) $item->line_total, 2, '.', '')));
            $dettaglioLinee->appendChild($dom->createElement('AliquotaIVA', number_format((float) $item->tax_rate, 2, '.', '')));

            if ($invoice->reverse_charge) {
                $dettaglioLinee->appendChild($dom->createElement('Natura', 'N6.9')); // Inversione contabile - altri casi
            }

            $datiBeniServizi->appendChild($dettaglioLinee);

            // Group VAT for DatiRiepilogo
            $rateKey = number_format((float) $item->tax_rate, 2, '.', '');
            if (! isset($vatAggregates[$rateKey])) {
                $vatAggregates[$rateKey] = [
                    'rate' => (float) $item->tax_rate,
                    'taxable' => 0.0,
                    'tax' => 0.0,
                ];
            }
            $vatAggregates[$rateKey]['taxable'] += (float) $item->line_total;
            $vatAggregates[$rateKey]['tax'] += ((float) $item->line_total * ((float) $item->tax_rate / 100.0));
        }

        // Summary records (DatiRiepilogo)
        foreach ($vatAggregates as $agg) {
            $datiRiepilogo = $dom->createElement('DatiRiepilogo');
            $datiRiepilogo->appendChild($dom->createElement('AliquotaIVA', number_format($agg['rate'], 2, '.', '')));
            $datiRiepilogo->appendChild($dom->createElement('ImponibileImporto', number_format($agg['taxable'], 2, '.', '')));
            $datiRiepilogo->appendChild($dom->createElement('Imposta', number_format($agg['tax'], 2, '.', '')));

            // Split Payment check (EsigibilitaIVA = 'S')
            if ($invoice->split_payment) {
                $datiRiepilogo->appendChild($dom->createElement('EsigibilitaIVA', 'S'));
            } elseif ($invoice->reverse_charge) {
                $datiRiepilogo->appendChild($dom->createElement('Natura', 'N6.9'));
            } else {
                $datiRiepilogo->appendChild($dom->createElement('EsigibilitaIVA', 'I')); // Immediata
            }

            $datiBeniServizi->appendChild($datiRiepilogo);
        }
        $body->appendChild($datiBeniServizi);

        // 2.3 DatiPagamento
        $datiPagamento = $dom->createElement('DatiPagamento');
        $datiPagamento->appendChild($dom->createElement('CondizioniPagamento', 'TP02')); // Completo

        $dettaglioPagamento = $dom->createElement('DettaglioPagamento');
        $dettaglioPagamento->appendChild($dom->createElement('ModalitaPagamento', 'MP05')); // Bonifico
        if ($invoice->due_date) {
            $dettaglioPagamento->appendChild($dom->createElement('DataScadenzaPagamento', $invoice->due_date->format('Y-m-d')));
        }
        $dettaglioPagamento->appendChild($dom->createElement('ImportoPagamento', number_format((float) $invoice->total_gross, 2, '.', '')));

        if (! empty($tenant->iban)) {
            $dettaglioPagamento->appendChild($dom->createElement('IBAN', preg_replace('/\s+/', '', $tenant->iban)));
        }
        $datiPagamento->appendChild($dettaglioPagamento);
        $body->appendChild($datiPagamento);

        return (string) $dom->saveXML();
    }
}
