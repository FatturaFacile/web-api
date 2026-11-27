<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Dto;

use Carbon\CarbonImmutable;

final readonly class GatewayReceipt
{
    public function __construct(public string $sixTransactionId, public CarbonImmutable $submittedAt) {}
}
