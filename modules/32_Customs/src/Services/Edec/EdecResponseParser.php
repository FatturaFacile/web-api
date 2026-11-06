<?php

declare(strict_types=1);

namespace Modules\Customs\Services\Edec;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Modules\Customs\DTOs\AssessmentReference;
use Modules\Customs\DTOs\SubmissionResult;
use Modules\Customs\Enums\AssessmentDocumentType;
use Modules\Customs\Exceptions\CustomsGatewayException;

/**
 * Reads the answers of e-dec. Each answer is checked against its BAZG schema first.
 */
final class EdecResponseParser
{
    private const string DECLARATION_NAMESPACE = 'http://www.e-dec.ch/xml/schema/edecResponse/v4';

    private const string RECEIPT_NAMESPACE = 'http://www.e-dec.ch/xml/schema/edecReceiptResponse/v3';

    public function __construct(private readonly EdecSchemaValidator $validator) {}

    public function declaration(string $xml): SubmissionResult
    {
        $this->assertValid($xml, EdecSchemaValidator::DECLARATION_RESPONSE);
        $xpath = $this->xpath($xml, self::DECLARATION_NAMESPACE);

        $acceptance = $xpath->query('/e:goodsDeclarationsResponse/e:goodsDeclarationAcceptance')->item(0);

        if ($acceptance !== null) {
            return new SubmissionResult(
                isAccepted: true,
                response: $xml,
                mrn: trim((string) $xpath->evaluate('string(e:customsDeclarationNumber)', $acceptance)),
                version: (int) $xpath->evaluate('string(e:customsDeclarationVersion)', $acceptance),
            );
        }

        $texts = [];

        foreach ($xpath->query('/e:goodsDeclarationsResponse/e:goodsDeclarationRejection/e:errors//*[not(*)]') as $node) {
            $texts[] = trim((string) $node->textContent);
        }

        return new SubmissionResult(
            isAccepted: false,
            response: $xml,
            rejectionReason: implode(' | ', array_values(array_filter($texts))) ?: 'Rejected by customs.',
        );
    }

    /**
     * @return list<AssessmentReference>
     */
    public function receiptList(string $xml): array
    {
        $this->assertValid($xml, EdecSchemaValidator::RECEIPT_RESPONSE);
        $xpath = $this->xpath($xml, self::RECEIPT_NAMESPACE);
        $this->assertNotRejected($xpath);

        $references = [];

        foreach ($xpath->query('/e:receiptRequestResponse/e:receiptList/e:receiptInformation') as $node) {
            $references[] = new AssessmentReference(
                mrn: trim((string) $xpath->evaluate('string(e:customsDeclarationNumber)', $node)),
                version: (int) $xpath->evaluate('string(e:customsDeclarationVersion)', $node),
                documentType: AssessmentDocumentType::from(trim((string) $xpath->evaluate('string(e:documentType)', $node))),
                documentDate: CarbonImmutable::parse((string) $xpath->evaluate('string(e:documentDate)', $node))->toDateString(),
            );
        }

        return $references;
    }

    /**
     * @throws CustomsGatewayException when the answer is a refusal
     */
    public function assertDocument(string $xml): void
    {
        $this->assertNotRejected($this->xpath($xml, self::RECEIPT_NAMESPACE));
    }

    private function assertNotRejected(DOMXPath $xpath): void
    {
        $rejection = $xpath->query('/e:receiptRequestResponse/e:receiptRequestRejection')->item(0);

        if ($rejection === null) {
            return;
        }

        $texts = [];

        foreach ($xpath->query('.//e:errors//*[not(*)]', $rejection) as $node) {
            $texts[] = trim((string) $node->textContent);
        }

        throw new CustomsGatewayException('The customs system refused the request: '.implode(' | ', array_values(array_filter($texts))));
    }

    private function assertValid(string $xml, string $schema): void
    {
        $errors = $this->validator->errors($xml, $schema);

        if ($errors !== []) {
            throw new CustomsGatewayException('The answer of the customs system does not match its schema: '.$errors[0]);
        }
    }

    private function xpath(string $xml, string $namespace): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadXML($xml, LIBXML_NONET);

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('e', $namespace);

        return $xpath;
    }
}
