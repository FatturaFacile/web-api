<?php

declare(strict_types=1);

namespace Modules\AccountsPayable\DTOs;

/**
 * The cash-discount terms that apply to a supplier document: `percentage` off when it is paid within `days` of the
 * document date, the full amount within `netDays`. The source tells where they come from (`document`,
 * `supplier_agreement` or `payment_term`).
 */
final readonly class SkontoTerms
{
    public const string SourceDocument = 'document';

    public const string SourceSupplierAgreement = 'supplier_agreement';

    public const string SourcePaymentTerm = 'payment_term';

    public function __construct(
        public string $percentage,
        public int $days,
        public ?int $netDays = null,
        public string $source = self::SourceSupplierAgreement,
        public ?string $reference = null,
    ) {}

    public function isUsable(): bool
    {
        return bccomp($this->percentage, '0', 2) > 0 && bccomp($this->percentage, '100', 2) <= 0 && $this->days >= 0;
    }
}
