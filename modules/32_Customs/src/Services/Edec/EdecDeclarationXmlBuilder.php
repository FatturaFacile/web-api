<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Edec;

use App\Models\Contact;
use DOMDocument;
use DOMElement;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Models\CustomsDeclaration;
use Modules\Customs\Models\CustomsDeclarationItem;
use Modules\Customs\Models\CustomsSettings;

/**
 * Builds the goodsDeclarations message of the e-dec schema v4.0 from a declaration. Only fields the application holds
 * are written; the numeric codes are the ones the user chose from the BAZG domains, never a default.
 */
final class EdecDeclarationXmlBuilder
{
    private const string NAMESPACE_URI = 'http://www.e-dec.ch/xml/schema/edec/v4';

    private const string SCHEMA_VERSION = '4.0';

    /** The correctionCode of a first transmission. */
    private const int FIRST_TRANSMISSION = 1;

    public function build(CustomsDeclaration $declaration, CustomsSettings $settings): string
    {
        $serviceType = $declaration->declaration_type->edecServiceType();

        if ($serviceType === null) {
            throw new CustomsException('e-dec carries import and export declarations only: transit has no e-dec message.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $root = $document->createElementNS(self::NAMESPACE_URI, 'goodsDeclarations');
        $root->setAttribute('schemaVersion', self::SCHEMA_VERSION);
        $document->appendChild($root);

        $node = $this->add($root, 'goodsDeclaration');
        $this->text($node, 'serviceType', $serviceType);
        $this->text($node, 'traderDeclarationNumber', $declaration->trader_declaration_number);
        $this->text($node, 'traderReference', $declaration->trader_reference);
        $this->text($node, 'clearanceLocation', $declaration->clearance_location);
        $this->text($node, 'declarationTime', $declaration->declaration_time);
        $this->text($node, 'declarationType', $declaration->declaration_kind);
        $this->text($node, 'correctionCode', self::FIRST_TRANSMISSION);
        $this->text($node, 'language', $declaration->language);
        $this->text($node, 'customsOfficeNumber', $declaration->customs_office_number);
        $this->text($node, 'dispatchCountry', $declaration->dispatch_country);
        $this->text($node, 'deliveryDestination', $declaration->destination_country);

        $transport = $this->add($node, 'transportMeans');
        $this->text($transport, 'transportMode', $declaration->transport_mode);
        $this->text($transport, 'transportationType', $declaration->transportation_type);
        $this->text($transport, 'transportationCountry', $declaration->transportation_country);
        $this->text($transport, 'transportationNumber', $declaration->transportation_number);

        $this->text($node, 'transportInContainer', $declaration->transport_in_container ? 1 : 0);

        foreach ($declaration->container_numbers ?? [] as $containerNumber) {
            $this->text($this->add($node, 'container'), 'containerNumber', $containerNumber);
        }

        $this->address($node, 'consignor', $declaration->consignor);
        $this->address($node, 'importer', $declaration->importer);
        $this->address($node, 'consignee', $declaration->consignee);
        $this->declarant($node, $settings);
        $this->business($node, $declaration, $settings);

        foreach ($declaration->items as $item) {
            $this->item($node, $item);
        }

        return (string) $document->saveXML();
    }

    private function declarant(DOMElement $parent, CustomsSettings $settings): void
    {
        $declarant = $this->add($parent, 'declarant');
        $this->text($declarant, 'traderIdentificationNumber', $settings->trader_identification_number);
        $this->text($declarant, 'declarantNumber', $settings->declarant_number);
        $this->text($declarant, 'name', $settings->declarant_name);
        $this->text($declarant, 'street', $settings->declarant_street);
        $this->text($declarant, 'postalCode', $settings->declarant_postal_code);
        $this->text($declarant, 'city', $settings->declarant_city);
        $this->text($declarant, 'country', $settings->declarant_country);
    }

    private function business(DOMElement $parent, CustomsDeclaration $declaration, CustomsSettings $settings): void
    {
        $business = $this->add($parent, 'business');
        $this->text($business, 'incoterms', $declaration->incoterms);
        $this->text($business, 'customsAccount', $settings->customs_account);
        $this->text($business, 'VATAccount', $settings->vat_account);
        $this->text($business, 'VATNumber', $settings->vat_number);
        $this->text($business, 'invoiceCurrencyType', $declaration->invoice_currency_type);
    }

    private function address(DOMElement $parent, string $name, ?Contact $contact): void
    {
        if ($contact === null) {
            return;
        }

        $address = $contact->addresses()->first();
        $element = $this->add($parent, $name);
        $this->text($element, 'name', $contact->display_name ?? $contact->company_name ?? trim($contact->first_name.' '.$contact->last_name));
        $this->text($element, 'street', trim(($address->street ?? '').' '.($address->house_number ?? '')));
        $this->text($element, 'postalCode', $address->postal_code ?? null);
        $this->text($element, 'city', $address->city ?? null);
        $this->text($element, 'country', $address->country ?? $contact->country);
    }

    private function item(DOMElement $parent, CustomsDeclarationItem $item): void
    {
        $node = $this->add($parent, 'goodsItem');
        $this->text($node, 'traderItemID', $item->trader_item_id);
        $this->text($node, 'description', $item->description);
        $this->text($node, 'commodityCode', $item->commodity_code);
        $this->text($node, 'statisticalCode', $item->statistical_code);
        $this->text($node, 'grossMass', $item->gross_mass);
        $this->text($node, 'netMass', $item->net_mass);
        $this->text($node, 'additionalUnit', $item->additional_unit);

        $statistic = $this->add($node, 'statistic');
        $this->text($statistic, 'customsClearanceType', $item->customs_clearance_type);
        $this->text($statistic, 'commercialGood', $item->commercial_good);
        $this->text($statistic, 'statisticalValue', $this->wholeFrancs((string) $item->statistical_value));

        if ($item->origin_country !== null) {
            $origin = $this->add($node, 'origin');
            $this->text($origin, 'originCountry', $item->origin_country);
            $this->text($origin, 'preference', $item->preference ? 1 : 0);
        }

        if ($item->packaging_type !== null) {
            $packaging = $this->add($node, 'packaging');
            $this->text($packaging, 'packagingType', $item->packaging_type);
            $this->text($packaging, 'quantity', $item->package_quantity);
            $this->text($packaging, 'packagingReferenceNumber', $item->packaging_reference);
        }

        foreach ($item->produced_documents ?? [] as $produced) {
            $document = $this->add($node, 'producedDocument');
            $this->text($document, 'documentType', $produced['document_type'] ?? null);
            $this->text($document, 'documentReferenceNumber', $produced['reference'] ?? null);
            $this->text($document, 'issueDate', $produced['issue_date'] ?? null);
        }

        $valuation = $this->add($node, 'valuation');
        $this->text($valuation, 'rate', $item->duty_rate);
        $this->text($valuation, 'VATValue', $item->vat_value === null ? null : $this->wholeFrancs((string) $item->vat_value));
        $this->text($valuation, 'VATCode', $item->vat_code);
    }

    /**
     * The schema takes these values as integers. Half a franc goes up.
     */
    private function wholeFrancs(string $amount): string
    {
        return bcadd($amount, '0.5', 0);
    }

    private function add(DOMElement $parent, string $name): DOMElement
    {
        $element = $parent->ownerDocument->createElementNS(self::NAMESPACE_URI, $name);
        $parent->appendChild($element);

        return $element;
    }

    /**
     * A field without a value is left out: the schema tells whether it may be.
     */
    private function text(DOMElement $parent, string $name, string|int|null $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $this->add($parent, $name)->appendChild($parent->ownerDocument->createTextNode((string) $value));
    }
}
