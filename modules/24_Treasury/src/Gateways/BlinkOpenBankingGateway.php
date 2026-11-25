<?php

declare(strict_types=1);

namespace Modules\Treasury\Gateways;

/**
 * SIX bLink account information: consent, balance and booked transactions of the accounts of a Swiss bank. The mapping
 * of the bLink answers (flat `transactions` list with a credit/debit indicator, structured QR references) is kept
 * here; base URL, token URL and paths come from the configuration of the bLink participant.
 */
final class BlinkOpenBankingGateway extends AbstractHttpOpenBankingGateway
{
    protected function configKey(): string
    {
        return 'blink';
    }

    protected function consentBody(string $iban): array
    {
        return [
            'accounts' => [['iban' => $iban]],
            'permissions' => ['accountDetails', 'balances', 'transactions'],
            'validUntil' => now()->addDays(90)->toDateString(),
            'recurringIndicator' => true,
        ];
    }

    protected function parseBalance(array $json): array
    {
        $balance = (array) (($json['balances'][0] ?? null) ?: $json);
        $amount = (array) ($balance['amount'] ?? $balance['balanceAmount'] ?? []);

        return [
            'currency' => strtoupper((string) ($amount['currency'] ?? $balance['currency'] ?? 'CHF')),
            'balance' => bcadd((string) ($amount['value'] ?? $amount['amount'] ?? $balance['value'] ?? '0'), '0', 2),
        ];
    }

    protected function parseTransactions(array $json): array
    {
        $rows = [];

        foreach ((array) ($json['transactions'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = ltrim((string) ($entry['amount']['value'] ?? $entry['amount'] ?? '0'), '-');
            $debit = strtoupper((string) ($entry['creditDebitIndicator'] ?? 'CRDT')) === 'DBIT';
            $reference = (string) ($entry['structuredReference'] ?? $entry['reference'] ?? '');
            $rows[] = [
                'external_id' => (string) ($entry['id'] ?? $entry['entryReference'] ?? sha1(json_encode($entry))),
                'booked_at' => (string) ($entry['bookingDate'] ?? now()->toDateString()),
                'amount' => ($debit ? '-' : '').bcadd($value, '0', 2),
                'currency' => strtoupper((string) ($entry['amount']['currency'] ?? $entry['currency'] ?? 'CHF')),
                'description' => trim($reference.' '.(string) ($entry['remittanceInformation'] ?? '')),
                'counterpart_name' => $entry['counterparty']['name'] ?? null,
                'counterpart_iban' => $entry['counterparty']['iban'] ?? null,
                'reference' => $reference !== '' ? $reference : ($entry['endToEndId'] ?? null),
            ];
        }

        return $rows;
    }
}
