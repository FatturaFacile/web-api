<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

/**
 * The fiscal verdict of a line: recoverable VAT, traceability, taxable benefit and the violations it raised.
 */
final readonly class LineAssessment
{
    /**
     * @param  list<array{code: string, severity: string, params: array<string, mixed>}>  $violations
     */
    public function __construct(
        public string $vatRecoverableAmount,
        public bool $isTraceable,
        public bool $isTaxableBenefit,
        public array $violations,
    ) {}
}
