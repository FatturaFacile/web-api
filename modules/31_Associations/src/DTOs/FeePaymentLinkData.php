<?php

declare(strict_types=1);

namespace Modules\Associations\DTOs;

final readonly class FeePaymentLinkData
{
    public function __construct(public string $providerReference, public string $url) {}
}
