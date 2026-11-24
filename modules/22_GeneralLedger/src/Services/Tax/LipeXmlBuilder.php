<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use DOMDocument;
use DOMElement;
use Modules\GeneralLedger\Exceptions\VatSettlementException;

/**
 * Writes the XML file of the Comunicazione dei dati delle liquidazioni periodiche IVA (LIPE).
 *
 * Specification followed: "Specifiche tecniche per la compilazione e la trasmissione telematica della comunicazione dei dati
 * delle liquidazioni periodiche IVA", supply code IVP18 (Agenzia delle Entrate, provv. 27 marzo 2018 and following updates),
 * namespace urn:www.agenziaentrate.gov.it:specificheTecniche:sco:ivp. Layout: Fornitura > Intestazione (CodiceFornitura,
 * CodiceFiscaleDichiarante, CodiceCarica) + Comunicazione[identificativo] > Frontespizio (the "Frontespizio" of the
 * model) + DatiContabili > Modulo (one VP module per month or quarter: boxes VP1 period/Subfornitura/EventiEccezionali,
 * VP2 TotaleOperazioniAttive, VP3 TotaleOperazioniPassive, VP4 IvaEsigibile, VP5 IvaDetratta, VP6 IvaDovuta/IvaCredito,
 * VP7 DebitoPrecedente, VP8 CreditoPeriodoPrecedente, VP9 CreditoAnnoPrecedente, VP10 VersamentiAutoUE, VP11 CreditiImposta,
 * VP12 InteressiDovuti, VP13 Metodo/Acconto, VP14 ImportoDaVersare/ImportoACredito). A communication holds up to
 * five modules, all with the same periodicity (month or quarter). The file name is ITxxxxxxxxxxxxxxx_LI_xxxxx.xml (sender
 * tax code, five character alphanumeric progressive).
 *
 * The official XSD is not redistributed with the application: the structural profile in resources/xsd mirrors it, and the
 * identifiers are checked by LipeIdentifierValidator. The file must still be checked with the control software of the
 * Agenzia delle Entrate before it is sent (directly or through an intermediary).
 */
final class LipeXmlBuilder
{
    public const string NAMESPACE_URI = 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:ivp';

    public const string SIGNATURE_NAMESPACE_URI = 'http://www.w3.org/2000/09/xmldsig#';

    /** Maximum number of VP modules of one communication. */
    public const int MAX_MODULES = 5;

    /**
     * Gets the file name (IT + sender tax code + _LI_ + five character alphanumeric progressive, upper-case base 36).
     */
    public static function fileName(LipeData $data, int $progressive = 1): string
    {
        $suffix = str_pad(strtoupper(base_convert((string) max(0, $progressive), 10, 36)), 5, '0', STR_PAD_LEFT);

        return sprintf('IT%s_LI_%s.xml', $data->taxCode, substr($suffix, -5));
    }

    /**
     * Builds the UTF-8 encoded XML document.
     */
    public static function build(LipeData $data): string
    {
        return self::buildModules($data, [$data]);
    }

    /**
     * Builds one communication with several modules: the months of a quarter of a monthly taxpayer, or the single quarter of
     * a quarterly one. The identity (tax code, VAT number, year, declarant) is read from $data, the amounts from each module.
     *
     * @param  non-empty-list<LipeData>  $modules
     */
    public static function buildModules(LipeData $data, array $modules): string
    {
        self::assertModules($modules);

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = self::element($document, 'Fornitura');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:iv', self::NAMESPACE_URI);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', self::SIGNATURE_NAMESPACE_URI);
        $document->appendChild($root);

        $header = self::element($document, 'Intestazione');
        $header->appendChild(self::element($document, 'CodiceFornitura', 'IVP18'));

        $front = self::element($document, 'Frontespizio');
        $front->appendChild(self::element($document, 'CodiceFiscale', $data->taxCode));
        $front->appendChild(self::element($document, 'AnnoImposta', (string) $data->year));
        $front->appendChild(self::element($document, 'PartitaIVA', $data->vatNumber));

        if ($data->declarantTaxCode !== null && trim($data->declarantTaxCode) !== '') {
            $header->appendChild(self::element($document, 'CodiceFiscaleDichiarante', $data->declarantTaxCode));
            $header->appendChild(self::element($document, 'CodiceCarica', $data->declarantRoleCode));
            $front->appendChild(self::element($document, 'CFDichiarante', $data->declarantTaxCode));
            $front->appendChild(self::element($document, 'CodiceCaricaDichiarante', $data->declarantRoleCode));
        }

        $front->appendChild(self::element($document, 'FirmaDichiarazione', '1'));

        $accounting = self::element($document, 'DatiContabili');

        foreach ($modules as $moduleData) {
            $accounting->appendChild(self::module($document, $moduleData));
        }

        $communication = self::element($document, 'Comunicazione');
        $communication->setAttribute('identificativo', '00001');
        $communication->appendChild($front);
        $communication->appendChild($accounting);

        $root->appendChild($header);
        $root->appendChild($communication);

        return (string) $document->saveXML();
    }

    /**
     * A communication holds at most five modules and all of them use the same periodicity.
     *
     * @param  list<LipeData>  $modules
     */
    private static function assertModules(array $modules): void
    {
        if ($modules === [] || count($modules) > self::MAX_MODULES) {
            throw VatSettlementException::invalid(sprintf('A LIPE communication holds from 1 to %d modules.', self::MAX_MODULES));
        }

        $monthly = $modules[0]->month !== null;

        foreach ($modules as $module) {
            if (($module->month !== null) !== $monthly) {
                throw VatSettlementException::invalid('The modules of a LIPE communication must all be monthly or all quarterly.');
            }
        }
    }

    private static function module(DOMDocument $document, LipeData $moduleData): DOMElement
    {
        $module = self::element($document, 'Modulo');
        $module->appendChild($moduleData->month !== null
            ? self::element($document, 'Mese', (string) $moduleData->month)
            : self::element($document, 'Trimestre', (string) ($moduleData->quarter ?? 1)));

        if ($moduleData->subcontracting) {
            $module->appendChild(self::element($document, 'Subfornitura', '1'));
        }

        if ($moduleData->exceptionalEvents !== null) {
            $module->appendChild(self::element($document, 'EventiEccezionali', (string) $moduleData->exceptionalEvents));
        }

        $difference = Decimal::sub($moduleData->outputVat, $moduleData->inputVat);

        self::addAmount($document, $module, 'TotaleOperazioniAttive', $moduleData->activeOperations);
        self::addAmount($document, $module, 'TotaleOperazioniPassive', $moduleData->passiveOperations);
        self::addAmount($document, $module, 'IvaEsigibile', $moduleData->outputVat);
        self::addAmount($document, $module, 'IvaDetratta', $moduleData->inputVat);
        self::addAmount($document, $module, 'IvaDovuta', $difference);
        self::addAmount($document, $module, 'IvaCredito', Decimal::neg($difference));
        self::addAmount($document, $module, 'DebitoPrecedente', $moduleData->previousDebit);
        self::addAmount($document, $module, 'CreditoPeriodoPrecedente', $moduleData->previousCredit);
        self::addAmount($document, $module, 'CreditoAnnoPrecedente', $moduleData->previousYearCredit);
        self::addAmount($document, $module, 'VersamentiAutoUE', $moduleData->euAutoPayments);
        self::addAmount($document, $module, 'CreditiImposta', $moduleData->taxCredits);
        self::addAmount($document, $module, 'InteressiDovuti', $moduleData->interest);
        if ($moduleData->advanceMethod !== null) {
            $module->appendChild(self::element($document, 'Metodo', (string) $moduleData->advanceMethod));
        }

        self::addAmount($document, $module, 'Acconto', $moduleData->advance);
        self::addAmount($document, $module, 'ImportoDaVersare', $moduleData->payable);
        self::addAmount($document, $module, 'ImportoACredito', Decimal::neg($moduleData->payable));

        return $module;
    }

    /**
     * Formats an amount as required by the specifications (comma as decimal separator, two decimals).
     */
    public static function formatAmount(string $value): string
    {
        return str_replace('.', ',', Decimal::round2($value));
    }

    private static function element(DOMDocument $document, string $name, ?string $value = null): DOMElement
    {
        $element = $document->createElementNS(self::NAMESPACE_URI, 'iv:'.$name);

        if ($value !== null) {
            $element->appendChild($document->createTextNode($value));
        }

        return $element;
    }

    private static function addAmount(DOMDocument $document, DOMElement $module, string $name, string $value): void
    {
        if (Decimal::isPositive($value)) {
            $module->appendChild(self::element($document, $name, self::formatAmount($value)));
        }
    }
}
