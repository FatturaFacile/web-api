<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use DOMDocument;
use DOMElement;

/**
 * Writes the XML file of the Comunicazione liquidazioni periodiche IVA (supply IVP18, namespace
 * urn:www.agenziaentrate.gov.it:specificheTecniche:sco:ivp). The file must be checked with the control software of the
 * Agenzia delle Entrate before it is sent (directly or through an intermediary).
 *
 * Port of FatturaFacile.Application.Taxation.LipeXmlBuilder.
 */
final class LipeXmlBuilder
{
    public const string NAMESPACE_URI = 'urn:www.agenziaentrate.gov.it:specificheTecniche:sco:ivp';

    /**
     * Gets the file name (IT + fiscal code + _LI_ + five character progressive).
     */
    public static function fileName(LipeData $data): string
    {
        $period = $data->month !== null
            ? sprintf('M%02d', $data->month)
            : sprintf('T%02d', $data->quarter ?? 1);

        return sprintf('IT%s_LI_%02d%s.xml', $data->taxCode, $data->year % 100, $period);
    }

    /**
     * Builds the UTF-8 encoded XML document.
     */
    public static function build(LipeData $data): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = self::element($document, 'Fornitura');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:iv', self::NAMESPACE_URI);
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

        $module = self::element($document, 'Modulo');
        $module->appendChild(self::element($document, 'NumeroModulo', '1'));
        $module->appendChild($data->month !== null
            ? self::element($document, 'Mese', (string) $data->month)
            : self::element($document, 'Trimestre', (string) ($data->quarter ?? 1)));

        $difference = Decimal::sub($data->outputVat, $data->inputVat);

        self::addAmount($document, $module, 'TotaleOperazioniAttive', $data->activeOperations);
        self::addAmount($document, $module, 'TotaleOperazioniPassive', $data->passiveOperations);
        self::addAmount($document, $module, 'IvaEsigibile', $data->outputVat);
        self::addAmount($document, $module, 'IvaDetratta', $data->inputVat);
        self::addAmount($document, $module, 'IvaDovuta', $difference);
        self::addAmount($document, $module, 'IvaCredito', Decimal::neg($difference));
        self::addAmount($document, $module, 'DebitoPrecedente', $data->previousDebit);
        self::addAmount($document, $module, 'CreditoPeriodoPrecedente', $data->previousCredit);
        self::addAmount($document, $module, 'InteressiDovuti', $data->interest);
        self::addAmount($document, $module, 'ImportoDaVersare', $data->payable);
        self::addAmount($document, $module, 'ImportoACredito', Decimal::neg($data->payable));

        $accounting = self::element($document, 'DatiContabili');
        $accounting->appendChild($module);

        $communication = self::element($document, 'Comunicazione');
        $communication->setAttribute('identificativo', '00001');
        $communication->appendChild($front);
        $communication->appendChild($accounting);

        $root->appendChild($header);
        $root->appendChild($communication);

        return (string) $document->saveXML();
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
