<?php

declare(strict_types=1);

namespace Modules\Associations\DTOs;

final readonly class FeePaymentConfirmation
{
    public function __construct(public string $providerReference, public string $amount, public bool $paid) {}
}
