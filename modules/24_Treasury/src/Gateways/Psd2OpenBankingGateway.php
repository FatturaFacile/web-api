<?php

declare(strict_types=1);

namespace Modules\Treasury\Gateways;

/**
 * NextGenPSD2 (Berlin Group) account information service: consents, balances and booked transactions.
 */
final class Psd2OpenBankingGateway extends AbstractHttpOpenBankingGateway
{
    protected function configKey(): string
    {
        return 'psd2';
    }

    protected function consentBody(string $iban): array
    {
        return [
            'access' => [
                'accounts' => [['iban' => $iban]],
                'balances' => [['iban' => $iban]],
                'transactions' => [['iban' => $iban]],
            ],
            'recurringIndicator' => true,
            'validUntil' => now()->addDays(90)->toDateString(),
            'frequencyPerDay' => 4,
            'combinedServiceIndicator' => false,
        ];
    }

    protected function parseBalance(array $json): array
    {
        $balances = (array) ($json['balances'] ?? []);
        $preferred = collect($balances)->first(fn (mixed $balance): bool => is_array($balance) && in_array($balance['balanceType'] ?? '', ['closingBooked', 'interimAvailable', 'expected'], true)) ?? ($balances[0] ?? []);
        $amount = (array) ($preferred['balanceAmount'] ?? []);

        return ['currency' => strtoupper((string) ($amount['currency'] ?? 'EUR')), 'balance' => bcadd((string) ($amount['amount'] ?? '0'), '0', 2)];
    }

    protected function parseTransactions(array $json): array
    {
        $rows = [];

        foreach ((array) ($json['transactions']['booked'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $amount = (array) ($entry['transactionAmount'] ?? []);
            $credit = bccomp((string) ($amount['amount'] ?? '0'), '0', 2) >= 0;
            $rows[] = [
                'external_id' => (string) ($entry['transactionId'] ?? $entry['entryReference'] ?? $entry['endToEndId'] ?? sha1(json_encode($entry))),
                'booked_at' => (string) ($entry['bookingDate'] ?? $entry['valueDate'] ?? now()->toDateString()),
                'amount' => bcadd((string) ($amount['amount'] ?? '0'), '0', 2),
                'currency' => strtoupper((string) ($amount['currency'] ?? 'EUR')),
                'description' => (string) ($entry['remittanceInformationUnstructured'] ?? $entry['additionalInformation'] ?? ''),
                'counterpart_name' => $credit ? ($entry['debtorName'] ?? null) : ($entry['creditorName'] ?? null),
                'counterpart_iban' => $credit ? ($entry['debtorAccount']['iban'] ?? null) : ($entry['creditorAccount']['iban'] ?? null),
                'reference' => $entry['endToEndId'] ?? null,
            ];
        }

        return $rows;
    }
}
