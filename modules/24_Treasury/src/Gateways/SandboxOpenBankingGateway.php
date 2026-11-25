<?php

declare(strict_types=1);

namespace Modules\Treasury\Gateways;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Treasury\Contracts\OpenBankingGateway;
use Modules\Treasury\Exceptions\OpenBankingUnavailableException;

/**
 * Deterministic test bank: every account holds a fixed balance and each day of a requested period carries the same
 * three movements (two incoming, one outgoing) with stable ids, so a repeated sync demonstrates idempotency. Nothing
 * leaves the application and it never runs in production.
 */
final class SandboxOpenBankingGateway implements OpenBankingGateway
{
    public function name(): string
    {
        return 'sandbox';
    }

    public function isAvailable(): bool
    {
        return ! app()->isProduction();
    }

    public function requestConsent(string $iban): array
    {
        $this->assertAvailable();

        return [
            'external_consent_id' => 'SANDBOX-'.Str::uuid7(),
            'access_token' => 'sandbox-'.Str::random(32),
            'expires_at' => now()->addDays(90)->toIso8601String(),
            'status' => 'active',
        ];
    }

    public function accountBalance(string $accessToken, string $iban): array
    {
        $this->assertAvailable();

        return ['iban' => $iban, 'currency' => str_starts_with(strtoupper($iban), 'CH') ? 'CHF' : 'EUR', 'balance' => '12500.00'];
    }

    public function transactions(string $accessToken, string $iban, string $from, string $to): array
    {
        $this->assertAvailable();
        $currency = str_starts_with(strtoupper($iban), 'CH') ? 'CHF' : 'EUR';
        $rows = [];

        for ($day = CarbonImmutable::parse($from); $day->lessThanOrEqualTo(CarbonImmutable::parse($to)); $day = $day->addDay()) {
            $date = $day->toDateString();

            foreach ([['IN-1', '1250.00', 'Sandbox customer A', 'Payment invoice 2026/001'], ['IN-2', '380.50', 'Sandbox customer B', 'Settlement invoice 2026/002'], ['OUT-1', '-95.00', 'Sandbox supplier', 'Supplier invoice 77']] as [$key, $amount, $name, $text]) {
                $rows[] = [
                    'external_id' => 'SBX-'.substr(hash('sha256', $iban.$date.$key), 0, 20),
                    'booked_at' => $date,
                    'amount' => $amount,
                    'currency' => $currency,
                    'description' => $text,
                    'counterpart_name' => $name,
                    'counterpart_iban' => null,
                    'reference' => 'SBX-'.$key.'-'.$day->format('Ymd'),
                ];
            }
        }

        return $rows;
    }

    public function initiatePayment(string $accessToken, array $payment): array
    {
        $this->assertAvailable();

        return ['external_payment_id' => 'SANDBOX-'.Str::uuid7(), 'status' => 'simulated'];
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
