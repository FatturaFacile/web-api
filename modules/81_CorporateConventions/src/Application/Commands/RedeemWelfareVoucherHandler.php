<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Application\Commands;

use Modules\CorporateConventions\Models\WelfareVoucherRedemption;
use Modules\CorporateConventions\Services\WelfareVoucherService;

/**
 * Records a welfare voucher paid at the till, validated by jurisdiction, with the merchant fee deducted.
 */
final class RedeemWelfareVoucherHandler
{
    public function __construct(
        private readonly WelfareVoucherService $vouchers,
    ) {}

    public function handle(RedeemWelfareVoucherCommand $command): WelfareVoucherRedemption
    {
        return $this->vouchers->redeem($command->data, $command->tenantId, $command->userId);
    }
}
