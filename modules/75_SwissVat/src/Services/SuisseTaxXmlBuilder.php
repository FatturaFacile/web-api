<?php

declare(strict_types=1);

namespace Modules\SwissVat\Services;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Modules\SwissVat\Domain\Enums\SwissVatMethod;
use Modules\SwissVat\Domain\ValueObjects\SwissUidMwst;
use Modules\SwissVat\Models\SwissVatConfig;
use Modules\SwissVat\Models\SwissVatReturn;

/**
 * Generates well-formed SuisseTax eCH-0217 v2.0 XML declarations for AFC / ESTV ePortal upload.
 */
final class SuisseTaxXmlBuilder
{
    public const string NAMESPACE_URI = 'http://www.ech.ch/xmlns/eCH-0217/2';

    public function __construct(
        private readonly SwissVatRateCatalogService $catalog,
    ) {}

    public function fileName(SwissVatReturn $vatReturn): string
    {
        return sprintf('suissetax-ech0217-%s.xml', $vatReturn->period_label);
    }

    public function build(SwissVatReturn $vatReturn, Tenant $tenant, ?SwissVatConfig $config = null): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:vatDeclaration');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('minorVersion', '0');
        $doc->appendChild($root);

        $uidRaw = $config?->uid_mwst ?: ($tenant->uid_number ?: $tenant->vat_number);
        $uid = SwissUidMwst::normalizeOrFallback($uidRaw ? (string) $uidRaw : null);

        $header = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:header');
        $this->appendElement($doc, $header, 'uid', $uid);
        $this->appendElement($doc, $header, 'companyName', (string) ($tenant->legal_name ?: $tenant->name ?: 'Swiss Taxpayer'));
        $this->appendElement($doc, $header, 'settlementMethod', (string) $vatReturn->accounting_method);
        $this->appendElement($doc, $header, 'considerationBasis', (string) $vatReturn->consideration_basis);
        $this->appendElement($doc, $header, 'periodFrom', CarbonImmutable::parse($vatReturn->period_from)->format('Y-m-d'));
        $this->appendElement($doc, $header, 'periodTo', CarbonImmutable::parse($vatReturn->period_to)->format('Y-m-d'));
        $this->appendElement($doc, $header, 'currency', (string) ($vatReturn->currency ?: 'CHF'));
        $this->appendElement($doc, $header, 'quadraturaPassed', $vatReturn->quadratura_passed ? 'true' : 'false');
        $root->appendChild($header);

        $boxes = $vatReturn->afc_boxes;
        $epoch = $this->catalog->ratesForDate(CarbonImmutable::parse($vatReturn->period_to));

        $turnoverSection = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:turnoverSection');
        foreach (['200', '205', '220', '221', '225', '230', '235', '280', '289', '299'] as $code) {
            $val = isset($boxes[$code]) && ! is_array($boxes[$code]) ? (string) $boxes[$code] : '0.00';
            $this->appendBox($doc, $turnoverSection, $code, SwissVatDecimal::round2($val));
        }
        $root->appendChild($turnoverSection);

        $taxSection = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:taxCalculationSection');
        if ($vatReturn->accounting_method === SwissVatMethod::Saldosteuersatz->value) {
            foreach ($boxes['sss_rates'] ?? [] as $idx => $sssRow) {
                $code = $idx === 0 ? $epoch['boxes']['sss1'] : $epoch['boxes']['sss2'];
                $this->appendRateBox(
                    $doc,
                    $taxSection,
                    $code,
                    (string) ($sssRow['base'] ?? '0.00'),
                    (string) ($sssRow['rate'] ?? '0.00'),
                    (string) ($sssRow['tax'] ?? '0.00'),
                );
            }
        } else {
            foreach ([$epoch['boxes']['standard'], $epoch['boxes']['reduced'], $epoch['boxes']['accommodation']] as $rateBoxCode) {
                $data = isset($boxes[$rateBoxCode]) && is_array($boxes[$rateBoxCode]) ? $boxes[$rateBoxCode] : [];
                $this->appendRateBox(
                    $doc,
                    $taxSection,
                    $rateBoxCode,
                    (string) ($data['base'] ?? '0.00'),
                    (string) ($data['rate'] ?? '0.00'),
                    (string) ($data['tax'] ?? '0.00'),
                );
            }
        }

        $bezCode = $epoch['boxes']['bezugsteuer'];
        $bezData = isset($boxes[$bezCode]) && is_array($boxes[$bezCode]) ? $boxes[$bezCode] : [];
        $this->appendRateBox(
            $doc,
            $taxSection,
            $bezCode,
            (string) ($bezData['base'] ?? '0.00'),
            (string) ($bezData['rate'] ?? $epoch['standard']),
            (string) ($bezData['tax'] ?? '0.00'),
        );
        $this->appendBox($doc, $taxSection, '399', SwissVatDecimal::round2((string) ($boxes['399'] ?? $vatReturn->box_399_total_output_tax)));
        $root->appendChild($taxSection);

        $inputSection = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:inputTaxSection');
        foreach (['400', '405', '410', '415', '420', '479'] as $code) {
            $val = isset($boxes[$code]) && ! is_array($boxes[$code]) ? (string) $boxes[$code] : '0.00';
            $this->appendBox($doc, $inputSection, $code, SwissVatDecimal::round2($val));
        }
        $root->appendChild($inputSection);

        $settlementSection = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:settlementSection');
        $this->appendBox($doc, $settlementSection, '500', SwissVatDecimal::round2((string) ($boxes['500'] ?? $vatReturn->box_500_payable_amount)));
        $this->appendBox($doc, $settlementSection, '510', SwissVatDecimal::round2((string) ($boxes['510'] ?? $vatReturn->box_510_credit_amount)));
        $root->appendChild($settlementSection);

        $otherFlows = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:otherFlowsSection');
        $this->appendBox($doc, $otherFlows, '900', SwissVatDecimal::round2((string) ($boxes['900'] ?? '0.00')));
        $this->appendBox($doc, $otherFlows, '910', SwissVatDecimal::round2((string) ($boxes['910'] ?? '0.00')));
        $root->appendChild($otherFlows);

        return (string) $doc->saveXML();
    }

    private function appendElement(DOMDocument $doc, DOMElement $parent, string $name, string $value): void
    {
        $el = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:'.$name);
        $el->appendChild($doc->createTextNode($value));
        $parent->appendChild($el);
    }

    private function appendBox(DOMDocument $doc, DOMElement $parent, string $code, string $amount): void
    {
        $el = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:box');
        $el->setAttribute('code', $code);
        $el->setAttribute('amount', SwissVatDecimal::round2($amount));
        $parent->appendChild($el);
    }

    private function appendRateBox(DOMDocument $doc, DOMElement $parent, string $code, string $base, string $rate, string $tax): void
    {
        $el = $doc->createElementNS(self::NAMESPACE_URI, 'eCH-0217:rateBox');
        $el->setAttribute('code', $code);
        $el->setAttribute('taxableBase', SwissVatDecimal::round2($base));
        $el->setAttribute('rate', SwissVatDecimal::round2($rate));
        $el->setAttribute('taxAmount', SwissVatDecimal::round2($tax));
        $parent->appendChild($el);
    }
}
