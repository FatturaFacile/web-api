<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\Reconciliation;

/**
 * How a statement line settles a document, in the currency of the document. All amounts are exact decimal strings.
 *
 * `fxDifference` is signed from the company's point of view: positive is an exchange gain, negative a loss.
 */
final readonly class Assessment
{
    public const string EXACT = 'exact';

    public const string FEE_TOLERANCE = 'fee_tolerance';

    public const string FX = 'fx';

    public const string PARTIAL = 'partial';

    public function __construct(
        public string $kind,
        public string $matchedAmount,
        public string $feeAmount,
        public string $fxDifference,
        public string $residualAmount,
        public ?string $rate = null,
    ) {}

    public function isComplete(): bool
    {
        return $this->kind !== self::PARTIAL;
    }
}
