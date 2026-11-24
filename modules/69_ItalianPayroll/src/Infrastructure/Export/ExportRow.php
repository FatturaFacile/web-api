<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Export;

/**
 * One attendance line of the export with the voice code already resolved for the consultant software.
 */
final readonly class ExportRow
{
    public function __construct(
        public string $matricola,
        public string $causal,
        public string $voiceCode,
        public string $quantity,
        public string $unit,
        public int $mealVouchers,
        public string $reimbursement,
        public string $fringeBenefit,
        public ?string $inpsProtocol,
    ) {}
}
