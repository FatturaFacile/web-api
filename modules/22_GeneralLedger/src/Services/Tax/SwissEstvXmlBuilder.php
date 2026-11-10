<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services\Tax;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Modules\GeneralLedger\Models\VatSettlement;

/**
 * Generates the official Swiss eCH-0217 v2.0 XML file for electronic VAT filing with the FTA/ESTV.
 */
final class SwissEstvXmlBuilder
{
    public const string NAMESPACE_URI = 'http://www.ech.ch/xmlns/eCH-0217/2';

    public static function fileName(VatSettlement $settlement): string
    {
        return sprintf('ech0217-mwst-%s.xml', $settlement->settlement_period);
    }

    public static function build(VatSettlement $settlement, Tenant $tenant): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:vatDeclaration');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('minorVersion', '0');
        $doc->appendChild($root);

        $header = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:header');
        self::appendElement($doc, $header, 'uid', self::formatUid((string) ($tenant->uid_number ?: $tenant->vat_number ?: 'CHE-000.000.000')));
        self::appendElement($doc, $header, 'companyName', (string) ($tenant->legal_name ?: $tenant->name ?: 'Swiss Tenant'));
        self::appendElement($doc, $header, 'settlementMethod', (string) ($settlement->regime ?? 'effective'));
        self::appendElement($doc, $header, 'periodFrom', CarbonImmutable::parse($settlement->period_from)->format('Y-m-d'));
        self::appendElement($doc, $header, 'periodTo', CarbonImmutable::parse($settlement->period_to)->format('Y-m-d'));
        self::appendElement($doc, $header, 'currency', (string) ($settlement->currency ?: 'CHF'));
        $root->appendChild($header);

        $boxes = is_array($settlement->estv_boxes) ? $settlement->estv_boxes : [];
        $turnoverSection = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:turnoverSection');
        foreach (['200', '220', '221', '225', '230', '235', '289', '299'] as $boxCode) {
            $val = isset($boxes[$boxCode]) && ! is_array($boxes[$boxCode]) ? (string) $boxes[$boxCode] : '0.00';
            self::appendBoxElement($doc, $turnoverSection, $boxCode, Decimal::round2($val));
        }
        $root->appendChild($turnoverSection);

        $taxCalculation = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:taxCalculationSection');
        foreach (['303', '313', '343', '383'] as $rateBoxCode) {
            $boxData = $boxes[$rateBoxCode] ?? ['base' => '0.00', 'rate' => '0.0', 'tax' => '0.00'];
            $el = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:rateBox');
            $el->setAttribute('code', $rateBoxCode);
            $el->setAttribute('taxableBase', Decimal::round2((string) ($boxData['base'] ?? '0.00')));
            $el->setAttribute('rate', (string) ($boxData['rate'] ?? '0.0'));
            $el->setAttribute('taxAmount', Decimal::round2((string) ($boxData['tax'] ?? '0.00')));
            $taxCalculation->appendChild($el);
        }
        self::appendBoxElement($doc, $taxCalculation, '399', Decimal::round2((string) ($boxes['399'] ?? $settlement->output_vat_amount ?? '0.00')));
        $root->appendChild($taxCalculation);

        $inputTaxSection = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:inputTaxSection');
        foreach (['400', '405', '410', '415', '420', '479'] as $inputBoxCode) {
            $val = isset($boxes[$inputBoxCode]) && ! is_array($boxes[$inputBoxCode]) ? (string) $boxes[$inputBoxCode] : '0.00';
            self::appendBoxElement($doc, $inputTaxSection, $inputBoxCode, Decimal::round2($val));
        }
        $root->appendChild($inputTaxSection);

        $settlementSection = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:settlementSection');
        self::appendBoxElement($doc, $settlementSection, '500', Decimal::round2((string) ($boxes['500'] ?? $settlement->net_vat_payable ?? '0.00')));
        self::appendBoxElement($doc, $settlementSection, '510', Decimal::round2((string) ($boxes['510'] ?? $settlement->net_vat_credit ?? '0.00')));
        $root->appendChild($settlementSection);

        return (string) $doc->saveXML();
    }

    private static function appendElement(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $el = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:'.$name);
        $el->appendChild($doc->createTextNode($value));
        $parent->appendChild($el);
    }

    private static function appendBoxElement(DOMDocument $doc, DOMElement $parent, string $code, string $amount): void
    {
        $el = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:box');
        $el->setAttribute('code', $code);
        $el->setAttribute('amount', $amount);
        $parent->appendChild($el);
    }

    private static function formatUid(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?: '000000000';
        $digits = str_pad(substr($digits, 0, 9), 9, '0', STR_PAD_RIGHT);

        return sprintf('CHE-%s.%s.%s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 3));
    }
}
