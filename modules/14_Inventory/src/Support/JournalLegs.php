<?php

declare(strict_types=1);

namespace Modules\Inventory\Support;

/**
 * Builds role-based journal legs carried by inventory valuation events. The General Ledger maps
 * each role (inventory, cogs, ppv, landed_cost_clearing) to a chart-of-accounts entry, honouring
 * the optional category-level account override.
 */
final class JournalLegs
{
    public const string ROLE_INVENTORY = 'inventory';

    public const string ROLE_COGS = 'cogs';

    public const string ROLE_PPV = 'ppv';

    public const string ROLE_LANDED_COST_CLEARING = 'landed_cost_clearing';

    /** @var array<string, array{role: string, account_id: string|null, net: string, description: string}> */
    private array $legs = [];

    /**
     * Adds a debit; a negative amount is booked as a credit.
     */
    public function debit(string $role, ?string $accountId, string $amount, string $description): self
    {
        $key = $role.'|'.($accountId ?? '');
        if (! isset($this->legs[$key])) {
            $this->legs[$key] = ['role' => $role, 'account_id' => $accountId, 'net' => '0', 'description' => $description];
        }

        $this->legs[$key]['net'] = Decimal::add($this->legs[$key]['net'], $amount);

        return $this;
    }

    /**
     * Adds a credit; a negative amount is booked as a debit.
     */
    public function credit(string $role, ?string $accountId, string $amount, string $description): self
    {
        return $this->debit($role, $accountId, Decimal::neg($amount), $description);
    }

    /**
     * @return list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>
     */
    public function toArray(): array
    {
        $result = [];
        foreach ($this->legs as $leg) {
            $net = Decimal::money($leg['net']);
            if (Decimal::isZero($net)) {
                continue;
            }

            $result[] = [
                'role' => $leg['role'],
                'account_id' => $leg['account_id'],
                'debit' => Decimal::isPositive($net) ? $net : '0.00',
                'credit' => Decimal::isNegative($net) ? Decimal::money(Decimal::abs($net)) : '0.00',
                'description' => $leg['description'],
            ];
        }

        return $result;
    }

    /**
     * Inverts every leg (used for reversal documents).
     *
     * @param  list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>  $legs
     * @return list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>
     */
    public static function invert(array $legs, string $descriptionPrefix = 'Reversal: '): array
    {
        return array_map(static fn (array $leg): array => [
            'role' => $leg['role'],
            'account_id' => $leg['account_id'],
            'debit' => $leg['credit'],
            'credit' => $leg['debit'],
            'description' => $descriptionPrefix.$leg['description'],
        ], $legs);
    }

    /**
     * @param  list<array{role: string, account_id: string|null, debit: string, credit: string, description: string}>  $legs
     */
    public static function isBalanced(array $legs): bool
    {
        $debit = '0';
        $credit = '0';
        foreach ($legs as $leg) {
            $debit = Decimal::add($debit, $leg['debit']);
            $credit = Decimal::add($credit, $leg['credit']);
        }

        return Decimal::comp($debit, $credit) === 0 && Decimal::isPositive($debit);
    }
}
