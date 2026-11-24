<?php

declare(strict_types=1);

namespace Modules\Treasury\Gateways;

use Illuminate\Support\Str;
use Modules\Treasury\Contracts\OpenBankingGateway;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;

/**
 * Simulator for development and tests: no bank is contacted, no money moves, and it refuses to run in production.
 */
final class LocalOpenBankingGateway implements OpenBankingGateway
{
    public function name(): string
    {
        return 'local';
    }

    public function isAvailable(): bool
    {
        return ! app()->isProduction();
    }

    public function requestConsent(string $iban): array
    {
        $this->assertAvailable();

        return [
            'external_consent_id' => 'LOCAL-'.Str::uuid7(),
            'access_token' => Str::random(40),
            'expires_at' => now()->addDays(90)->toIso8601String(),
            'status' => 'active',
        ];
    }

    public function accountBalance(string $accessToken, string $iban): array
    {
        $this->assertAvailable();

        return ['iban' => $iban, 'currency' => 'EUR', 'balance' => '0.00'];
    }

    public function transactions(string $accessToken, string $iban, string $from, string $to): array
    {
        $this->assertAvailable();

        return [];
    }

    public function initiatePayment(string $accessToken, array $payment): array
    {
        $this->assertAvailable();

        return ['external_payment_id' => 'LOCAL-'.Str::uuid7(), 'status' => 'simulated'];
    }

    public function paymentStatus(string $accessToken, string $externalPaymentId): string
    {
        $this->assertAvailable();

        return 'simulated';
    }

    private function assertAvailable(): void
    {
        if (! $this->isAvailable()) {
            throw OpenBankingUnavailableException::simulatorInProduction();
        }
    }
}
