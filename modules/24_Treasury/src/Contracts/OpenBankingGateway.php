<?php

declare(strict_types=1);

namespace Modules\Treasury\Contracts;

use Modules\Treasury\Exceptions\OpenBankingUnavailableException;

/**
 * Account information and payment initiation of a PSD2 provider. A real driver is written once a licensed provider or a
 * bank agreement exists.
 */
interface OpenBankingGateway
{
    public function name(): string;

    public function isAvailable(): bool;

    /**
     * @return array{external_consent_id: string, access_token: string, expires_at: string, status: string}
     *
     * @throws OpenBankingUnavailableException
     */
    public function requestConsent(string $iban): array;

    /**
     * @return array{iban: string, currency: string, balance: string}
     *
     * @throws OpenBankingUnavailableException
     */
    public function accountBalance(string $accessToken, string $iban): array;

    /**
     * @return list<array{external_id: string, booked_at: string, amount: string, currency: string, description: string}>
     *
     * @throws OpenBankingUnavailableException
     */
    public function transactions(string $accessToken, string $iban, string $from, string $to): array;

    /**
     * @param  array{debtor_iban: string, creditor_iban: string, creditor_name: string, amount: string, currency: string, remittance_information: ?string}  $payment
     * @return array{external_payment_id: string, status: string}
     *
     * @throws OpenBankingUnavailableException
     */
    public function initiatePayment(string $accessToken, array $payment): array;

    /**
     * @throws OpenBankingUnavailableException
     */
    public function paymentStatus(string $accessToken, string $externalPaymentId): string;
}
