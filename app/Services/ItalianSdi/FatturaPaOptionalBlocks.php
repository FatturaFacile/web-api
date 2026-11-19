<?php

declare(strict_types=1);

namespace App\Services\ItalianSdi;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

/**
 * Emits the optional FatturaPA v1.2.3 blocks an invoice carries as plain data (`sdi_extra` of the invoice, `sdi_data` of a
 * line) and keeps every element in the order the XSD sequence requires.
 *
 * The data uses the official element names as keys: an associative array is one element, a list is a repeated element,
 * a scalar is its text. Keys the schema does not know are refused instead of silently dropped.
 */
final class FatturaPaOptionalBlocks
{
    /**
     * Child sequence of every element the invoice data may touch (XSD order).
     *
     * @var array<string, list<string>>
     */
    private const ORDER = [
        'FatturaElettronicaHeader' => ['DatiTrasmissione', 'CedentePrestatore', 'RappresentanteFiscale', 'CessionarioCommittente', 'TerzoIntermediarioOSoggettoEmittente', 'SoggettoEmittente'],
        'DatiTrasmissione' => ['IdTrasmittente', 'ProgressivoInvio', 'FormatoTrasmissione', 'CodiceDestinatario', 'ContattiTrasmittente', 'PECDestinatario'],
        'ContattiTrasmittente' => ['Telefono', 'Email'],
        'CedentePrestatore' => ['DatiAnagrafici', 'Sede', 'StabileOrganizzazione', 'IscrizioneREA', 'Contatti', 'RiferimentoAmministrazione'],
        'CessionarioCommittente' => ['DatiAnagrafici', 'Sede', 'StabileOrganizzazione', 'RappresentanteFiscale'],
        'RappresentanteFiscale' => ['DatiAnagrafici', 'IdFiscaleIVA', 'Denominazione', 'Nome', 'Cognome'],
        'TerzoIntermediarioOSoggettoEmittente' => ['DatiAnagrafici'],
        'DatiAnagrafici' => ['IdFiscaleIVA', 'CodiceFiscale', 'Anagrafica', 'AlboProfessionale', 'ProvinciaAlbo', 'NumeroIscrizioneAlbo', 'DataIscrizioneAlbo', 'RegimeFiscale'],
        'IdFiscaleIVA' => ['IdPaese', 'IdCodice'],
        'Anagrafica' => ['Denominazione', 'Nome', 'Cognome', 'Titolo', 'CodEORI'],
        'Sede' => ['Indirizzo', 'NumeroCivico', 'CAP', 'Comune', 'Provincia', 'Nazione'],
        'StabileOrganizzazione' => ['Indirizzo', 'NumeroCivico', 'CAP', 'Comune', 'Provincia', 'Nazione'],
        'IscrizioneREA' => ['Ufficio', 'NumeroREA', 'CapitaleSociale', 'SocioUnico', 'StatoLiquidazione'],
        'Contatti' => ['Telefono', 'Fax', 'Email'],
        'DatiGenerali' => ['DatiGeneraliDocumento', 'DatiOrdineAcquisto', 'DatiContratto', 'DatiConvenzione', 'DatiRicezione', 'DatiFattureCollegate', 'DatiSAL', 'DatiDDT', 'DatiTrasporto', 'FatturaPrincipale'],
        'DatiGeneraliDocumento' => ['TipoDocumento', 'Divisa', 'Data', 'Numero', 'DatiRitenuta', 'DatiBollo', 'DatiCassaPrevidenziale', 'ScontoMaggiorazione', 'ImportoTotaleDocumento', 'Arrotondamento', 'Causale', 'Art73'],
        'DatiOrdineAcquisto' => ['RiferimentoNumeroLinea', 'IdDocumento', 'Data', 'NumItem', 'CodiceCommessaConvenzione', 'CodiceCUP', 'CodiceCIG'],
        'DatiContratto' => ['RiferimentoNumeroLinea', 'IdDocumento', 'Data', 'NumItem', 'CodiceCommessaConvenzione', 'CodiceCUP', 'CodiceCIG'],
        'DatiConvenzione' => ['RiferimentoNumeroLinea', 'IdDocumento', 'Data', 'NumItem', 'CodiceCommessaConvenzione', 'CodiceCUP', 'CodiceCIG'],
        'DatiRicezione' => ['RiferimentoNumeroLinea', 'IdDocumento', 'Data', 'NumItem', 'CodiceCommessaConvenzione', 'CodiceCUP', 'CodiceCIG'],
        'DatiFattureCollegate' => ['RiferimentoNumeroLinea', 'IdDocumento', 'Data', 'NumItem', 'CodiceCommessaConvenzione', 'CodiceCUP', 'CodiceCIG'],
        'DatiSAL' => ['RiferimentoFase'],
        'DatiDDT' => ['NumeroDDT', 'DataDDT', 'RiferimentoNumeroLinea'],
        'DatiTrasporto' => ['DatiAnagraficiVettore', 'MezzoTrasporto', 'CausaleTrasporto', 'NumeroColli', 'Descrizione', 'UnitaMisuraPeso', 'PesoLordo', 'PesoNetto', 'DataOraRitiro', 'DataInizioTrasporto', 'TipoResa', 'IndirizzoResa', 'DataOraConsegna'],
        'DatiAnagraficiVettore' => ['IdFiscaleIVA', 'CodiceFiscale', 'Anagrafica', 'NumeroLicenzaGuida'],
        'IndirizzoResa' => ['Indirizzo', 'NumeroCivico', 'CAP', 'Comune', 'Provincia', 'Nazione'],
        'FatturaPrincipale' => ['NumeroFatturaPrincipale', 'DataFatturaPrincipale'],
        'ScontoMaggiorazione' => ['Tipo', 'Percentuale', 'Importo'],
        'DatiBeniServizi' => ['DettaglioLinee', 'DatiRiepilogo'],
        'DettaglioLinee' => ['NumeroLinea', 'TipoCessionePrestazione', 'CodiceArticolo', 'Descrizione', 'Quantita', 'UnitaMisura', 'DataInizioPeriodo', 'DataFinePeriodo', 'PrezzoUnitario', 'ScontoMaggiorazione', 'PrezzoTotale', 'AliquotaIVA', 'Ritenuta', 'Natura', 'RiferimentoAmministrazione', 'AltriDatiGestionali'],
        'CodiceArticolo' => ['CodiceTipo', 'CodiceValore'],
        'AltriDatiGestionali' => ['TipoDato', 'RiferimentoTesto', 'RiferimentoNumero', 'RiferimentoData'],
        'DatiRiepilogo' => ['AliquotaIVA', 'Natura', 'SpeseAccessorie', 'Arrotondamento', 'ImponibileImporto', 'Imposta', 'EsigibilitaIVA', 'RiferimentoNormativo'],
        'FatturaElettronicaBody' => ['DatiGenerali', 'DatiBeniServizi', 'DatiVeicoli', 'DatiPagamento', 'Allegati'],
        'DatiPagamento' => ['CondizioniPagamento', 'DettaglioPagamento'],
        'DettaglioPagamento' => ['Beneficiario', 'ModalitaPagamento', 'DataRiferimentoTerminiPagamento', 'GiorniTerminiPagamento', 'DataScadenzaPagamento', 'ImportoPagamento', 'CodUfficioPostale', 'CognomeQuietanzante', 'NomeQuietanzante', 'CFQuietanzante', 'TitoloQuietanzante', 'IstitutoFinanziario', 'IBAN', 'ABI', 'CAB', 'BIC', 'ScontoPagamentoAnticipato', 'DataLimitePagamentoAnticipato', 'PenalitaPagamentiRitardati', 'DataDecorrenzaPenale', 'CodicePagamento'],
        'Allegati' => ['NomeAttachment', 'AlgoritmoCompressione', 'FormatoAttachment', 'DescrizioneAttachment', 'Attachment'],
    ];

    /**
     * Elements the schema allows more than once: they accumulate, every other element is replaced by the data given.
     *
     * @var list<string>
     */
    private const REPEATABLE = [
        'DatiRitenuta', 'DatiCassaPrevidenziale', 'ScontoMaggiorazione', 'Causale', 'DatiOrdineAcquisto', 'DatiContratto',
        'DatiConvenzione', 'DatiRicezione', 'DatiFattureCollegate', 'DatiSAL', 'DatiDDT', 'RiferimentoNumeroLinea',
        'RiferimentoFase', 'CodiceArticolo', 'AltriDatiGestionali', 'DettaglioLinee', 'DatiRiepilogo', 'DettaglioPagamento',
        'Allegati',
    ];

    /**
     * Max length of one Causale element in the XSD: a longer wording is split over several elements.
     */
    public const CausaleLength = 200;

    /**
     * Merge `$data` into `$parent` as children and put the children in schema order.
     *
     * @param  array<string, mixed>  $data  official element name => value
     *
     * @throws InvalidArgumentException for an element the schema does not allow under `$parent`
     */
    public function merge(DOMDocument $dom, DOMElement $parent, array $data): void
    {
        $order = self::ORDER[$parent->nodeName] ?? null;

        foreach ($data as $name => $value) {
            if ($order !== null && ! in_array($name, $order, true)) {
                throw new InvalidArgumentException("Element {$name} is not allowed in {$parent->nodeName} of the FatturaPA schema.");
            }

            $this->mergeChild($dom, $parent, (string) $name, $value);
        }

        $this->sort($parent);
    }

    /**
     * Put the children of `$element` and of every known descendant in schema order (stable for repeated elements).
     */
    public function sort(DOMElement $element): void
    {
        $order = self::ORDER[$element->nodeName] ?? null;
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        foreach ($children as $child) {
            $this->sort($child);
        }

        if ($order === null || count($children) < 2) {
            return;
        }

        $position = array_flip($order);
        $indexed = [];

        foreach ($children as $i => $child) {
            $indexed[] = [$position[$child->nodeName] ?? PHP_INT_MAX, $i, $child];
        }

        usort($indexed, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        foreach ($indexed as [, , $child]) {
            $element->appendChild($child);
        }
    }

    /**
     * Split a wording in chunks the Causale element accepts.
     *
     * @return list<string>
     */
    public function chunkCausale(string $wording): array
    {
        return mb_str_split(trim($wording), self::CausaleLength);
    }

    private function mergeChild(DOMDocument $dom, DOMElement $parent, string $name, mixed $value): void
    {
        if (! in_array($name, self::REPEATABLE, true)) {
            foreach (iterator_to_array($parent->childNodes) as $existing) {
                if ($existing instanceof DOMElement && $existing->nodeName === $name) {
                    if (is_array($value) && ! array_is_list($value)) {
                        $this->merge($dom, $existing, $value);

                        return;
                    }

                    $parent->removeChild($existing);
                }
            }
        }

        $items = is_array($value) && array_is_list($value) ? $value : [$value];

        foreach ($items as $item) {
            if ($item === null || $item === '' || $item === []) {
                continue;
            }

            $parent->appendChild($this->element($dom, $name, $item));
        }
    }

    private function element(DOMDocument $dom, string $name, mixed $value): DOMElement
    {
        $element = $dom->createElement($name);

        if (is_array($value)) {
            $this->merge($dom, $element, $value);

            return $element;
        }

        $element->appendChild($dom->createTextNode(is_bool($value) ? ($value ? 'SI' : 'NO') : (string) $value));

        return $element;
    }
}
