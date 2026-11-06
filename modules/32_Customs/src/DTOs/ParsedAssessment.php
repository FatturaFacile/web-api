<?php

declare(strict_types=1);

namespace Modules\Customs\DTOs;

use Modules\Customs\Enums\AssessmentDocumentType;

/**
 * The data the module reads out of an assessment decision.
 */
final readonly class ParsedAssessment
{
    /**
     * @param  list<array{rate: string, base: string, amount: string}>  $vatByRate
     */
    public function __construct(
        public AssessmentDocumentType $documentType,
        public string $mrn,
        public int $version,
        public string $documentDate,
        public ?string $traderDeclarationNumber,
        public string $dutyAmount,
        public string $vatAmount,
        public string $vatBaseAmount,
        public ?string $vatRate,
        public bool $amountNotCharged,
        public array $vatByRate,
    ) {}
}
