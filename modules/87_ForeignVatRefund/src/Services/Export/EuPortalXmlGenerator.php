<?php

declare(strict_types=1);

namespace Modules\ForeignVatRefund\Services\Export;

use App\Models\Tenant;
use DOMDocument;
use DOMElement;
use Modules\ForeignVatRefund\Domain\Exceptions\ForeignVatException;
use Modules\ForeignVatRefund\Models\ForeignVatLedgerEntry;
use Modules\ForeignVatRefund\Models\VatRefundClaim;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * The electronic refund application of Directive 2008/9/EC (Art. 38-bis1 DPR 633/72) for the portal of the Member State of
 * establishment, validated against the XSD of the module before it is handed out.
 */
final class EuPortalXmlGenerator
{
    public const string XML_NAMESPACE = 'urn:fatturafacile:foreign-vat-refund:eu-portal:1.0';

    public const string VERSION = '1.0';

    public function xsdPath(): string
    {
        return dirname(__DIR__, 3).'/resources/xsd/eu-vat-refund-application.xsd';
    }

    /**
     * @throws ForeignVatException applicant_vat_number_missing | xml_invalid
     */
    public function generate(VatRefundClaim $claim): string
    {
        $tenant = Tenant::query()->whereKey($claim->tenant_id)->first();
        $vatNumber = trim((string) ($tenant?->vat_number ?: $tenant?->uid_number));

        if ($tenant === null || $vatNumber === '') {
            throw ForeignVatException::because('applicant_vat_number_missing');
        }

        $items = $claim->items()->get();
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $root = $document->createElementNS(self::XML_NAMESPACE, 'VatRefundApplication');
        $root->setAttribute('claimNumber', $claim->claim_number);
        $root->setAttribute('version', self::VERSION);
        $document->appendChild($root);

        $applicant = $this->add($document, $root, 'Applicant');
        $this->text($document, $applicant, 'Name', (string) ($tenant->legal_name ?: $tenant->name));
        $this->text($document, $applicant, 'VatNumber', $vatNumber);
        $this->text($document, $applicant, 'CountryCode', strtoupper((string) $claim->home_country_code));

        if ($tenant->iban !== null && $tenant->iban !== '') {
            $this->text($document, $applicant, 'Iban', strtoupper(str_replace(' ', '', (string) $tenant->iban)));
        }

        $this->text($document, $root, 'RefundMemberState', $claim->target_country_code);
        $period = $this->add($document, $root, 'Period');
        $this->text($document, $period, 'Type', $claim->period_type->value);
        $this->text($document, $period, 'StartDate', $claim->period_start_date->format('Y-m-d'));
        $this->text($document, $period, 'EndDate', $claim->period_end_date->format('Y-m-d'));
        $this->text($document, $root, 'Currency', $claim->currency_code);

        $list = $this->add($document, $root, 'Items');
        $totalVat = '0';
        $totalRefund = '0';
        $sequence = 0;

        /** @var ForeignVatLedgerEntry $item */
        foreach ($items as $item) {
            $sequence++;
            $node = $this->add($document, $list, 'Item');
            $this->text($document, $node, 'Sequence', (string) $sequence);
            $this->text($document, $node, 'ExpenseCode', (string) $item->expense_category_code);

            if ($item->supplier_name !== null && $item->supplier_name !== '') {
                $this->text($document, $node, 'SupplierName', $item->supplier_name);
            }

            if ($item->supplier_vat_number !== null && $item->supplier_vat_number !== '') {
                $this->text($document, $node, 'SupplierVatId', $item->supplier_vat_number);
            }

            $this->text($document, $node, 'SupplierCountry', $item->supplier_country_code);
            $this->text($document, $node, 'InvoiceNumber', $item->document_number);
            $this->text($document, $node, 'InvoiceDate', $item->document_date->format('Y-m-d'));
            $this->text($document, $node, 'TaxableBase', Decimal::round2((string) $item->net_amount));
            $this->text($document, $node, 'VatAmount', Decimal::round2((string) $item->foreign_vat_amount));
            $this->text($document, $node, 'DeductiblePercentage', Decimal::round2((string) $item->eligible_percentage));
            $this->text($document, $node, 'DeductibleVatAmount', Decimal::round2((string) $item->recoverable_vat_amount));

            if ($item->attachment_path !== null) {
                $this->text($document, $node, 'AttachmentRef', basename($item->attachment_path));
            }

            $totalVat = Decimal::add($totalVat, (string) $item->foreign_vat_amount);
            $totalRefund = Decimal::add($totalRefund, (string) $item->recoverable_vat_amount);
        }

        $totals = $this->add($document, $root, 'Totals');
        $this->text($document, $totals, 'ItemCount', (string) $sequence);
        $this->text($document, $totals, 'TotalVatAmount', Decimal::round2($totalVat));
        $this->text($document, $totals, 'TotalRefundAmount', Decimal::round2($totalRefund));

        $this->assertValid($document);

        return (string) $document->saveXML();
    }

    private function assertValid(DOMDocument $document): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $document->schemaValidate($this->xsdPath())) {
                $messages = array_map(static fn ($error): string => trim($error->message), libxml_get_errors());
                libxml_clear_errors();

                throw ForeignVatException::because('xml_invalid', ['violations' => array_slice($messages, 0, 5)]);
            }
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    private function add(DOMDocument $document, DOMElement $parent, string $name): DOMElement
    {
        $element = $document->createElementNS(self::XML_NAMESPACE, $name);
        $parent->appendChild($element);

        return $element;
    }

    private function text(DOMDocument $document, DOMElement $parent, string $name, string $value): void
    {
        $element = $document->createElementNS(self::XML_NAMESPACE, $name);
        $element->appendChild($document->createTextNode($value));
        $parent->appendChild($element);
    }
}
