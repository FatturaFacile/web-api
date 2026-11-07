<?php

declare(strict_types=1);

namespace Modules\Treasury\Gateways;

use Modules\Treasury\Contracts\OpenBankingGateway;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;

/**
 * The default until a provider exists: every call refuses, so no payment can look initiated when it was not.
 */
final class RefusingOpenBankingGateway implements OpenBankingGateway
{
    public function name(): string
    {
        return 'refused';
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function requestConsent(string $iban): array
    {
        throw OpenBankingUnavailableException::providerRequired();
    }

    public function accountBalance(string $accessToken, string $iban): array
    {
        throw OpenBankingUnavailableException::providerRequired();
    }

    public function transactions(string $accessToken, string $iban, string $from, string $to): array
    {
        throw OpenBankingUnavailableException::providerRequired();
    }

    public function initiatePayment(string $accessToken, array $payment): array
    {
        throw OpenBankingUnavailableException::providerRequired();
    }

    public function paymentStatus(string $accessToken, string $externalPaymentId): string
    {
        throw OpenBankingUnavailableException::providerRequired();
    }
}
