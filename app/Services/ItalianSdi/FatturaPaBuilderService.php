<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use App\Models\Invoice;
use App\Services\Invoicing\Tax\TenantFiscalSettings;
use App\Support\Money;
use DOMDocument;
use DOMElement;
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
        $datiAnagraficiCedente->appendChild($dom->createElement('RegimeFiscale', TenantFiscalSettings::fromTenant($tenant)->regimeFiscale));
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
        $this->appendFiscalGeneralData($dom, $datiGeneraliDoc, $invoice);
        $datiGeneraliDoc->appendChild($dom->createElement('ImportoTotaleDocumento', $this->amount($invoice->total_gross)));

        foreach ($this->causaleChunks($invoice) as $causale) {
            $datiGeneraliDoc->appendChild($dom->createElement('Causale', htmlspecialchars($causale)));
        }

        $datiGenerali->appendChild($datiGeneraliDoc);
        $body->appendChild($datiGenerali);

        // 2.2 DatiBeniServizi
        $datiBeniServizi = $dom->createElement('DatiBeniServizi');
        $lineNumber = 1;
        $hasWithholding = (bool) $invoice->withholding_tax_enabled || $invoice->enasarco_amount !== null;
        $reverseChargeNatura = (string) config('italian_tax.reverse_charge.natura', 'N6.9');

        foreach ($invoice->items as $item) {
            $natura = $item->natura ?? ($invoice->reverse_charge ? $reverseChargeNatura : null);

            $dettaglioLinee = $dom->createElement('DettaglioLinee');
            $dettaglioLinee->appendChild($dom->createElement('NumeroLinea', (string) $lineNumber++));
            $dettaglioLinee->appendChild($dom->createElement('Descrizione', htmlspecialchars(substr($item->description, 0, 1000))));
            $dettaglioLinee->appendChild($dom->createElement('Quantita', Money::fromScaled(Money::toScaled((string) $item->quantity, 2), 2)));
            $dettaglioLinee->appendChild($dom->createElement('PrezzoUnitario', Money::fromScaled(Money::toScaled((string) $item->unit_price, 4), 4)));
            $dettaglioLinee->appendChild($dom->createElement('PrezzoTotale', $this->amount($item->line_total)));
            $dettaglioLinee->appendChild($dom->createElement('AliquotaIVA', $natura !== null ? '0.00' : $this->amount($item->tax_rate)));

            if ($hasWithholding && $item->withholding_applies !== false) {
                $dettaglioLinee->appendChild($dom->createElement('Ritenuta', 'SI'));
            }

            if ($natura !== null) {
                $dettaglioLinee->appendChild($dom->createElement('Natura', $natura));
            }

            $datiBeniServizi->appendChild($dettaglioLinee);
        }

        // Summary records (DatiRiepilogo), one per VAT rate / natura, from the stored calculator result.
        $regime = TenantFiscalSettings::fromTenant($tenant);

        foreach ($this->vatSummary($invoice) as $row) {
            $datiRiepilogo = $dom->createElement('DatiRiepilogo');
            $datiRiepilogo->appendChild($dom->createElement('AliquotaIVA', $row['rate']));

            if ($row['natura'] !== null) {
                $datiRiepilogo->appendChild($dom->createElement('Natura', $row['natura']));
            }

            $datiRiepilogo->appendChild($dom->createElement('ImponibileImporto', $row['taxable']));
            $datiRiepilogo->appendChild($dom->createElement('Imposta', $row['tax']));

            if ($row['natura'] === null) {
                $datiRiepilogo->appendChild($dom->createElement('EsigibilitaIVA', $row['payability'] ?? 'I'));
            } else {
                $reference = $this->riferimentoNormativo($row['natura'], $regime);

                if ($reference !== null) {
                    $datiRiepilogo->appendChild($dom->createElement('RiferimentoNormativo', htmlspecialchars($reference)));
                }
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
        $dettaglioPagamento->appendChild($dom->createElement('ImportoPagamento', $this->paymentAmount($invoice)));

        if (! empty($tenant->iban)) {
            $dettaglioPagamento->appendChild($dom->createElement('IBAN', preg_replace('/\s+/', '', $tenant->iban)));
        }
        $datiPagamento->appendChild($dettaglioPagamento);
        $body->appendChild($datiPagamento);

        return (string) $dom->saveXML();
    }

    /**
     * DatiRitenuta (ritenuta and ENASARCO as RT04), DatiBollo and DatiCassaPrevidenziale, in XSD order.
     */
    private function appendFiscalGeneralData(DOMDocument $dom, DOMElement $datiGeneraliDoc, Invoice $invoice): void
    {
        if ($invoice->withholding_tax_enabled && $invoice->withholding_tax_amount !== null) {
            $datiGeneraliDoc->appendChild($this->ritenutaElement(
                $dom,
                (string) ($invoice->withholding_tax_type ?? config('italian_tax.withholding.default_type', 'RT01')),
                $invoice->withholding_tax_amount,
                $invoice->withholding_tax_percent,
                (string) ($invoice->withholding_tax_reason ?? config('italian_tax.withholding.default_reason', 'A')),
            ));
        }

        if ($invoice->enasarco_amount !== null) {
            $datiGeneraliDoc->appendChild($this->ritenutaElement(
                $dom,
                (string) config('italian_tax.enasarco.withholding_type', 'RT04'),
                $invoice->enasarco_amount,
                $invoice->enasarco_percent,
                (string) config('italian_tax.enasarco.default_reason', 'R'),
            ));
        }

        if ($invoice->stamp_duty_applied) {
            $datiBollo = $dom->createElement('DatiBollo');
            $datiBollo->appendChild($dom->createElement('BolloVirtuale', 'SI'));
            $datiBollo->appendChild($dom->createElement('ImportoBollo', $this->amount($invoice->stamp_duty_amount ?? config('italian_tax.stamp_duty.amount', '2.00'))));
            $datiGeneraliDoc->appendChild($datiBollo);
        }

        if ($invoice->fund_enabled && $invoice->fund_amount !== null) {
            $fundBase = 0;

            foreach ($invoice->items as $item) {
                if ($item->fund_applies !== false) {
                    $fundBase += Money::toCents((string) $item->line_total);
                }
            }

            $cassa = $dom->createElement('DatiCassaPrevidenziale');
            $cassa->appendChild($dom->createElement('TipoCassa', (string) $invoice->fund_type));
            $cassa->appendChild($dom->createElement('AlCassa', $this->amount($invoice->fund_percent)));
            $cassa->appendChild($dom->createElement('ImportoContributoCassa', $this->amount($invoice->fund_amount)));
            $cassa->appendChild($dom->createElement('ImponibileCassa', Money::fromCents($fundBase)));
            $cassa->appendChild($dom->createElement('AliquotaIVA', $invoice->fund_natura !== null ? '0.00' : $this->amount($invoice->fund_vat_rate)));

            if ($invoice->withholding_tax_enabled && $invoice->withholding_tax_includes_fund) {
                $cassa->appendChild($dom->createElement('Ritenuta', 'SI'));
            }

            if ($invoice->fund_natura !== null) {
                $cassa->appendChild($dom->createElement('Natura', (string) $invoice->fund_natura));
            }

            $datiGeneraliDoc->appendChild($cassa);
        }
    }

    private function ritenutaElement(DOMDocument $dom, string $type, mixed $amount, mixed $rate, string $reason): DOMElement
    {
        $element = $dom->createElement('DatiRitenuta');
        $element->appendChild($dom->createElement('TipoRitenuta', $type));
        $element->appendChild($dom->createElement('ImportoRitenuta', $this->amount($amount)));
        $element->appendChild($dom->createElement('AliquotaRitenuta', $this->amount($rate)));
        $element->appendChild($dom->createElement('CausalePagamento', $reason));

        return $element;
    }

    /**
     * Legal notices split in Causale blocks of at most 200 characters (split on word boundaries).
     *
     * @return list<string>
     */
    private function causaleChunks(Invoice $invoice): array
    {
        $maxLength = (int) config('italian_tax.causale_max_length', 200);
        $chunks = [];

        foreach ((array) ($invoice->legal_notices ?? []) as $notice) {
            $notice = trim((string) $notice);

            while (mb_strlen($notice) > $maxLength) {
                $slice = mb_substr($notice, 0, $maxLength);
                $cut = mb_strrpos($slice, ' ');
                $cut = $cut === false || $cut === 0 ? $maxLength : $cut;
                $chunks[] = rtrim(mb_substr($notice, 0, $cut));
                $notice = ltrim(mb_substr($notice, $cut));
            }

            if ($notice !== '') {
                $chunks[] = $notice;
            }
        }

        return $chunks;
    }

    /**
     * VAT summary rows: the stored calculator result, or (legacy invoices without it) an aggregate of the stored
     * lines with the tax rounded once per rate / natura.
     *
     * @return list<array{rate: string, natura: string|null, taxable: string, tax: string, payability: string|null}>
     */
    private function vatSummary(Invoice $invoice): array
    {
        if (is_array($invoice->vat_summary) && $invoice->vat_summary !== []) {
            return array_values(array_map(fn (array $row): array => [
                'rate' => $this->amount($row['rate'] ?? '0'),
                'natura' => isset($row['natura']) && $row['natura'] !== '' ? (string) $row['natura'] : null,
                'taxable' => $this->amount($row['taxable'] ?? '0'),
                'tax' => $this->amount($row['tax'] ?? '0'),
                'payability' => isset($row['payability']) ? (string) $row['payability'] : null,
            ], $invoice->vat_summary));
        }

        $reverseChargeNatura = (string) config('italian_tax.reverse_charge.natura', 'N6.9');
        $payability = $invoice->split_payment ? 'S' : (in_array($invoice->vat_payability, ['I', 'D'], true) ? (string) $invoice->vat_payability : 'I');
        $rows = [];

        foreach ($invoice->items as $item) {
            $natura = $item->natura ?? ($invoice->reverse_charge ? $reverseChargeNatura : null);
            $rate = $natura !== null ? 0 : Money::toScaled((string) $item->tax_rate, 2);
            $key = $rate.'|'.($natura ?? '');
            $rows[$key] ??= ['rate' => $rate, 'natura' => $natura, 'taxable' => 0];
            $rows[$key]['taxable'] += Money::toCents((string) $item->line_total);
        }

        return array_values(array_map(fn (array $row): array => [
            'rate' => Money::fromScaled($row['rate'], 2),
            'natura' => $row['natura'],
            'taxable' => Money::fromCents($row['taxable']),
            'tax' => Money::fromCents(Money::percentOfBasisPoints($row['taxable'], $row['rate'])),
            'payability' => $row['natura'] === null ? $payability : null,
        ], $rows));
    }

    private function riferimentoNormativo(string $natura, TenantFiscalSettings $settings): ?string
    {
        $reference = $settings->isForfettario() && $natura === config('italian_tax.forfettario.natura', 'N2.2')
            ? config('italian_tax.forfettario.riferimento_normativo')
            : config("italian_tax.riferimento_normativo.{$natura}");

        return is_string($reference) && $reference !== '' ? mb_substr($reference, 0, 100) : null;
    }

    /**
     * ImportoPagamento: the stored amount due, or for legacy invoices the total minus split-payment VAT and withholding.
     */
    private function paymentAmount(Invoice $invoice): string
    {
        if ($invoice->total_due !== null) {
            return $this->amount($invoice->total_due);
        }

        $due = Money::toCents((string) $invoice->total_gross)
            - ($invoice->split_payment ? Money::toCents((string) $invoice->total_tax) : 0)
            - ($invoice->withholding_tax_enabled ? Money::toCents((string) $invoice->withholding_tax_amount) : 0);

        return Money::fromCents($due);
    }

    /**
     * A stored decimal formatted with two decimals, without float arithmetic.
     */
    private function amount(mixed $value): string
    {
        return Money::fromCents(Money::toCents(is_int($value) || is_float($value) ? $value : (string) $value));
    }
}
