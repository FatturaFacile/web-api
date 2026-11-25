<?php

declare(strict_types=1);

namespace Modules\ExpenseManagement\Domain\ValueObjects;

use Modules\ExpenseManagement\Domain\Enums\PaymentMethod;

/**
 * What a fiscal strategy needs to know about an expense line.
 */
final readonly class LineFacts
{
    public function __construct(
        public string $categoryCode,
        public bool $isMeal,
        public bool $isLodging,
        public bool $isTransport,
        public bool $categoryVatRecoverable,
        public PaymentMethod $paymentMethod,
        public bool $declaredTraceable,
        public string $amountInBase,
        public string $vatAmount,
        public ?string $merchantTaxId,
        public bool $blockNonTraceable,
    ) {}
}
