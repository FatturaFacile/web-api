<?php

declare(strict_types=1);

use Modules\Inventory\Support\JournalLegs;

test('legs net per role and account, drop zero legs and stay balanced', function (): void {
    $legs = (new JournalLegs)
        ->debit(JournalLegs::ROLE_INVENTORY, null, '30.00', 'inventory')
        ->debit(JournalLegs::ROLE_COGS, null, '20.00', 'cogs')
        ->debit(JournalLegs::ROLE_PPV, null, '0.00', 'ppv')
        ->debit(JournalLegs::ROLE_INVENTORY, null, '-5.00', 'inventory')
        ->credit(JournalLegs::ROLE_LANDED_COST_CLEARING, null, '45.00', 'clearing')
        ->toArray();

    expect($legs)->toBe([
        ['role' => 'inventory', 'account_id' => null, 'debit' => '25.00', 'credit' => '0.00', 'description' => 'inventory'],
        ['role' => 'cogs', 'account_id' => null, 'debit' => '20.00', 'credit' => '0.00', 'description' => 'cogs'],
        ['role' => 'landed_cost_clearing', 'account_id' => null, 'debit' => '0.00', 'credit' => '45.00', 'description' => 'clearing'],
    ])->and(JournalLegs::isBalanced($legs))->toBeTrue();
});

test('negative debits become credits and inversion mirrors every leg', function (): void {
    $legs = (new JournalLegs)
        ->debit(JournalLegs::ROLE_INVENTORY, 'acc-1', '-5.00', 'down')
        ->credit(JournalLegs::ROLE_COGS, null, '-5.00', 'offset')
        ->toArray();

    expect($legs[0]['credit'])->toBe('5.00')
        ->and($legs[1]['debit'])->toBe('5.00')
        ->and($legs[0]['account_id'])->toBe('acc-1');

    $inverted = JournalLegs::invert($legs);
    expect($inverted[0]['debit'])->toBe('5.00')
        ->and($inverted[1]['credit'])->toBe('5.00')
        ->and(JournalLegs::isBalanced($inverted))->toBeTrue();
});
