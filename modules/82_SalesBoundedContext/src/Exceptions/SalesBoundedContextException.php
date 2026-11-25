<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Exceptions;

use DomainException;

final class SalesBoundedContextException extends DomainException
{
    public static function invalidFiscalIdentity(string $message): self
    {
        return new self($message);
    }

    public static function creditHoldBlocked(string $customerCode): self
    {
        return new self("Customer [{$customerCode}] is on credit/insolvency hold.");
    }

    public static function frameworkCeilingExceeded(string $agreementNumber, float $remaining, float $requested): self
    {
        return new self("Framework agreement [{$agreementNumber}] remaining ceiling ({$remaining}) is below requested call-off ({$requested}).");
    }

    public static function frameworkExpired(string $agreementNumber): self
    {
        return new self("Framework agreement [{$agreementNumber}] is outside its validity window.");
    }

    public static function marginGuardrailBreached(float $actualMargin, float $minMargin): self
    {
        return new self("Margin [{$actualMargin}%] is below minimum guardrail [{$minMargin}%] and requires manager approval.");
    }

    public static function invalidStateTransition(string $message): self
    {
        return new self($message);
    }
}
