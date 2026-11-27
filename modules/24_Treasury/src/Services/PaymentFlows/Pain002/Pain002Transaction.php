<?php

declare(strict_types=1);

namespace Modules\Treasury\Services\PaymentFlows\Pain002;

/**
 * Status of one credit transfer reported by the bank (`TxInfAndSts`): the ISO 20022 status code, and for a rejection
 * the reason code (`StsRsnInf/Rsn/Cd`, or the proprietary code) with the free text the bank adds (`AddtlInf`).
 */
final readonly class Pain002Transaction
{
    public function __construct(
        public ?string $endToEndId,
        public ?string $instructionId,
        public string $status,
        public ?string $reasonCode = null,
        public ?string $additionalInfo = null,
    ) {}

    public function isRejected(): bool
    {
        return $this->status === Pain002Report::REJECTED;
    }
}
