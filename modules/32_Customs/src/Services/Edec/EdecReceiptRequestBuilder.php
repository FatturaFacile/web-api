<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Edec;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\Models\CustomsSettings;

/**
 * Builds the receiptRequest messages of the edecReceipt schema v3.0: the list of a period and a single document.
 */
final class EdecReceiptRequestBuilder
{
    private const string NAMESPACE_URI = 'http://www.e-dec.ch/xml/schema/edecReceiptRequest/v3';

    private const string SCHEMA_VERSION = '3.0';

    public const string ALL_IMPORT_DOCUMENTS = 'allImportTaxationDecisionsAndRefunds';

    public const string EXPORT_DOCUMENTS = 'taxationDecisionExport';

    /** The role of the account holder in the schema. */
    private const int ROLE_ACCOUNT_HOLDER = 1;

    public function list(CustomsSettings $settings, CarbonImmutable $from, CarbonImmutable $to, string $documentTypes): string
    {
        [$document, $root] = $this->start($settings);

        $list = $this->add($root, 'receiptList');
        $this->add($list, 'role', (string) $settings->receipt_role);

        if ((int) $settings->receipt_role === self::ROLE_ACCOUNT_HOLDER) {
            $this->add($list, 'accountNumber', (string) $settings->customs_account);
        }

        $range = $this->add($list, 'dateRange');
        $this->add($range, 'startDate', $from->toDateString());
        $this->add($range, 'endDate', $to->toDateString());
        $this->add($list, 'documentTypeList', $documentTypes);

        return (string) $document->saveXML();
    }

    public function single(CustomsSettings $settings, AssessmentReference $reference): string
    {
        [$document, $root] = $this->start($settings);

        $receipt = $this->add($root, 'receipt');
        $this->add($receipt, 'customsDeclarationNumber', $reference->mrn);
        $this->add($receipt, 'customsDeclarationVersion', (string) $reference->version);
        $this->add($receipt, 'documentType', $reference->documentType->value);

        return (string) $document->saveXML();
    }

    /**
     * @return array{DOMDocument, DOMElement}
     */
    private function start(CustomsSettings $settings): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(self::NAMESPACE_URI, 'receiptRequest');
        $root->setAttribute('schemaVersion', self::SCHEMA_VERSION);
        $document->appendChild($root);

        $this->add($root, 'requestorTraderIdentificationNumber', (string) $settings->trader_identification_number);
        $this->add($root, 'requestorCorrelationID', Str::limit((string) Str::uuid(), 35, ''));

        return [$document, $root];
    }

    private function add(DOMElement $parent, string $name, ?string $value = null): DOMElement
    {
        $element = $parent->ownerDocument->createElementNS(self::NAMESPACE_URI, $name);

        if ($value !== null) {
            $element->appendChild($parent->ownerDocument->createTextNode($value));
        }

        $parent->appendChild($element);

        return $element;
    }
}
