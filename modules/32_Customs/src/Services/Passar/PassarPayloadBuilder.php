<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Passar;

use DOMDocument;
use DOMElement;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsSettings;

/**
 * Builds an XML payload for the Swiss Passar channel (the BAZG system replacing e-dec). It reuses the data of the
 * e-dec declaration and is NOT validated against a BAZG Passar XSD: none is held by this repository, so the payload is
 * for inspection and for the sandbox channel only.
 */
final class PassarPayloadBuilder
{
    public const string NAMESPACE_URI = 'urn:fattura-facile:sandbox:passar:1';

    public function build(CustomsDeclaration $declaration, CustomsSettings $settings): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::NAMESPACE_URI, 'passarDeclaration');
        $document->appendChild($root);

        $header = $document->createElement('header');
        $root->appendChild($header);

        $this->fill($document, $header, [
            'direction' => $declaration->declaration_type->value,
            'traderDeclarationNumber' => $declaration->trader_declaration_number,
            'traderReference' => $declaration->trader_reference,
            'transitDocument' => $declaration->transit_document_type->value,
            'customsOffice' => $declaration->customs_office_number,
            'borderCrossing' => $declaration->border_crossing,
            'incoterms' => $declaration->incoterms,
            'dispatchCountry' => $declaration->dispatch_country,
            'destinationCountry' => $declaration->destination_country,
            'declarantNumber' => $settings->declarant_number,
            'traderIdentificationNumber' => $settings->trader_identification_number,
            'totalGrossMass' => (string) $declaration->total_gross_mass,
            'totalNetMass' => (string) $declaration->total_net_mass,
            'totalStatisticalValue' => (string) $declaration->total_statistical_value,
        ]);

        foreach ($declaration->items as $item) {
            $node = $document->createElement('goodsItem');
            $node->setAttribute('number', (string) $item->line_number);
            $this->fill($document, $node, [
                'description' => $item->description,
                'tariffNumber' => $item->commodity_code,
                'originCountry' => $item->origin_country,
                'preference' => $item->preference ? '1' : '0',
                'grossMass' => (string) $item->gross_mass,
                'netMass' => (string) $item->net_mass,
                'statisticalValue' => (string) $item->statistical_value,
            ]);
            $root->appendChild($node);
        }

        return (string) $document->saveXML();
    }

    /**
     * @param  array<string, string|int|null>  $values
     */
    private function fill(DOMDocument $document, DOMElement $parent, array $values): void
    {
        foreach ($values as $name => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $child = $document->createElement($name);
            $child->appendChild($document->createTextNode((string) $value));
            $parent->appendChild($child);
        }
    }
}
