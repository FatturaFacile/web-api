<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Carbon\CarbonImmutable;
use Modules\CorporateConventions\Domain\Enums\SourceDocumentType;

/**
 * Commits discount and employer subsidy atomically and records the split billing of the purchase.
 */
final readonly class ApplyConventionAndSplitBillingOnCheckoutCommand
{
    public function __construct(
        public string $enrollmentId,
        /** @var list<array<string, mixed>> */
        public array $lines,
        public SourceDocumentType $documentType,
        public string $documentId,
        public ?string $employeeDocumentId = null,
        public ?CarbonImmutable $transactionDate = null,
        public ?string $currency = null,
        public ?string $userId = null,
    ) {}
}
