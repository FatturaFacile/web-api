<?php

declare(strict_types=1);

namespace Modules\Customs\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Modules\Customs\DTOs\ParsedAssessment;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Exceptions\CustomsException;
use Modules\Customs\Services\Edec\EdecSchemaValidator;
use Modules\GeneralLedger\Services\Tax\Decimal;

/**
 * Reads a receiptRequestResponse of the edecReceipt schema v3.0 holding one assessment decision.
 */
final class AssessmentDecisionParser
{
    private const string NAMESPACE_URI = 'http://www.e-dec.ch/xml/schema/edecReceiptResponse/v3';

    public function __construct(private readonly EdecSchemaValidator $validator) {}

    /**
     * @throws CustomsException when the file is not an assessment decision
     */
    public function parse(string $xml): ParsedAssessment
    {
        $errors = $this->validator->errors($xml, EdecSchemaValidator::RECEIPT_RESPONSE);

        if ($errors !== []) {
            throw new CustomsException('The file does not match the BAZG schema: '.$errors[0]);
        }

        $document = new DOMDocument;
        $document->loadXML($xml, LIBXML_NONET);
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('e', self::NAMESPACE_URI);

        $names = array_map(static fn (AssessmentDocumentType $type): string => 'self::e:'.$type->value, AssessmentDocumentType::cases());
        $node = $xpath->query('/e:receiptRequestResponse/*['.implode(' or ', $names).']')->item(0);

        if (! $node instanceof DOMElement) {
            throw new CustomsException('The file holds no assessment decision.');
        }

        $read = static fn (string $path): string => trim((string) $xpath->evaluate("string({$path})", $node));
        $vatGroups = $this->vatByRate($xpath, $node);
        $vatBase = array_reduce($vatGroups, static fn (string $total, array $group): string => Decimal::add($total, $group['base']), '0');
        $number = $read('e:traderDeclarationNumber');

        return new ParsedAssessment(
            documentType: AssessmentDocumentType::from($node->localName),
            mrn: $read('e:documentInformation/e:documentNumber'),
            version: (int) $read('e:documentInformation/e:documentVersion'),
            documentDate: $read('e:documentInformation/e:documentDate'),
            traderDeclarationNumber: $number === '' ? null : $number,
            dutyAmount: $this->amount($read('e:valuationSummary/e:dutyAndAdditionalTaxes/e:totalAmount')),
            vatAmount: $this->amount($read('e:valuationSummary/e:VAT/e:totalAmount')),
            vatBaseAmount: $vatBase,
            vatRate: count($vatGroups) === 1 ? $vatGroups[0]['rate'] : null,
            amountNotCharged: $read('e:valuationSummary/e:VAT/e:amountNotCharged') === '1',
            vatByRate: $vatGroups,
        );
    }

    /**
     * The base and the tax of the goods items, by VAT rate.
     *
     * @return list<array{rate: string, base: string, amount: string}>
     */
    private function vatByRate(DOMXPath $xpath, DOMElement $decision): array
    {
        $groups = [];

        foreach ($xpath->query('e:goodsItem/e:valuationDetails/e:VAT', $decision) as $item) {
            $rate = Decimal::formatRate(trim((string) $xpath->evaluate('string(e:rate)', $item)) ?: '0');
            $current = $groups[$rate] ?? ['rate' => $rate, 'base' => '0', 'amount' => '0'];
            $groups[$rate] = [
                'rate' => $rate,
                'base' => Decimal::add($current['base'], $this->amount(trim((string) $xpath->evaluate('string(e:basisForAssessment)', $item)))),
                'amount' => Decimal::add($current['amount'], $this->amount(trim((string) $xpath->evaluate('string(e:amount)', $item)))),
            ];
        }

        return array_values($groups);
    }

    private function amount(string $value): string
    {
        return $value === '' ? '0' : $value;
    }
}
