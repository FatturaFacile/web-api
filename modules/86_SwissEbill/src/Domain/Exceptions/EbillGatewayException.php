<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Exceptions;

/**
 * The SIX Paynet / PostFinance gateway refused or could not be reached. HTTP 429, 5xx and network failures are transient
 * (the job retries with backoff); everything else (schema rejection, 4xx) is permanent.
 */
final class EbillGatewayException extends EbillException
{
    public function __construct(string $message, public readonly ?int $gatewayStatus = null, public readonly ?string $gatewayCode = null, private readonly bool $networkFailure = false)
    {
        parent::__construct($gatewayCode !== null && $gatewayCode !== '' ? $gatewayCode : 'gateway_error', $message, 502);
    }

    public function isTransient(): bool
    {
        return $this->networkFailure || $this->gatewayStatus === 429 || ($this->gatewayStatus !== null && $this->gatewayStatus >= 500);
    }
}
