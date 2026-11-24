<?php

declare(strict_types=1);

use Modules\AccountsPayable\Services\SkontoClauseParser;

it('reads the discount terms of a payment clause', function (string $clause, string $percentage, int $days, ?int $netDays): void {
    $terms = (new SkontoClauseParser)->parse($clause, 'CNT-7');

    expect($terms)->not->toBeNull()
        ->and($terms->percentage)->toBe($percentage)
        ->and($terms->days)->toBe($days)
        ->and($terms->netDays)->toBe($netDays)
        ->and($terms->reference)->toBe('CNT-7');
})->with([
    'italian' => ['30 gg data fattura, 2% skonto entro 10 gg', '2.00', 10, 30],
    'italian long' => ['Pagamento a 60 giorni, sconto cassa 3,5% se pagato entro 8 giorni', '3.50', 8, 60],
    'german' => ['2% Skonto innerhalb 10 Tagen, netto 30 Tage', '2.00', 10, 30],
    'french' => ['30 jours net, escompte 1,5% sous 15 jours', '1.50', 15, 30],
    'english' => ['2% discount within 10 days, net 30 days', '2.00', 10, 30],
    'english shorthand' => ['2/10 net 30', '2.00', 10, 30],
    'discount only' => ['1% skonto entro 5 gg', '1.00', 5, null],
]);

it('finds no terms in a clause without a discount', function (string $clause): void {
    expect((new SkontoClauseParser)->parse($clause))->toBeNull();
})->with([
    'net only' => ['30 gg data fattura'],
    'empty' => [''],
    'percentage without days' => ['sconto 5%'],
    'zero percent' => ['0% skonto entro 10 gg'],
]);
