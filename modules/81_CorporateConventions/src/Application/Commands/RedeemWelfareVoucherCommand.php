<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

/**
 * Records a welfare voucher paid at the till, validated by jurisdiction, with the merchant fee deducted.
 */
final readonly class RedeemWelfareVoucherCommand
{
    public function __construct(
        /** @var array<string, mixed> */
        public array $data,
        public string $tenantId,
        public ?string $userId = null,
    ) {}
}
