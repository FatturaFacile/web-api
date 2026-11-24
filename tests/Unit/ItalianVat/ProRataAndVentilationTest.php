<?php

declare(strict_types=1);

use Modules\ItalianVat\Exceptions\ItalianVatException;
use Modules\ItalianVat\Services\ProRataService;
use Modules\ItalianVat\Services\ReceiptsRegisterService;

it('rounds the pro-rata percentage up to the next unit (art. 19-bis)', function (string $deductible, string $total, int $expected): void {
    expect((new ProRataService)->percentage($deductible, $total))->toBe($expected);
})->with([
    'exact' => ['700.00', '1000.00', 70],
    'above a unit' => ['66.01', '100.00', 67],
    'repeating decimal' => ['2000.00', '3000.00', 67],
    'everything deductible' => ['500.00', '500.00', 100],
    'nothing deductible' => ['0.00', '500.00', 0],
    'no operations at all' => ['0.00', '0.00', 100],
]);

it('refuses deductible operations above the total operations', function (): void {
    expect(fn () => (new ProRataService)->percentage('1500.00', '1000.00'))->toThrow(ItalianVatException::class);
});

it('splits the VAT in deductible and non-deductible with the percentage', function (): void {
    expect((new ProRataService)->split('220.00', 70))->toBe(['deductible' => '154.00', 'non_deductible' => '66.00'])
        ->and((new ProRataService)->split('100.00', 100))->toBe(['deductible' => '100.00', 'non_deductible' => '0.00'])
        ->and((new ProRataService)->split('33.33', 50))->toBe(['deductible' => '16.67', 'non_deductible' => '16.66']);
});

it('ventilates the takings in proportion to the purchases and scorporates the VAT', function (): void {
    $result = app(ReceiptsRegisterService::class)->ventilate('1000.00', [
        ['rate' => 22, 'amount' => 600],
        ['rate' => 10, 'amount' => 300],
        ['rate' => 4, 'amount' => 100],
    ]);

    expect(array_column($result['lines'], 'gross'))->toBe(['600.00', '300.00', '100.00'])
        ->and(array_column($result['lines'], 'taxable'))->toBe(['491.80', '272.73', '96.15'])
        ->and(array_column($result['lines'], 'vat'))->toBe(['108.20', '27.27', '3.85'])
        ->and(array_column($result['lines'], 'share'))->toBe(['60.00', '30.00', '10.00'])
        ->and($result['total_taxable'])->toBe('860.68')
        ->and($result['total_vat'])->toBe('139.32');
});

it('gives the rounding remainder of the ventilation to the last rate', function (): void {
    $result = app(ReceiptsRegisterService::class)->ventilate('100.00', [
        ['rate' => 22, 'amount' => 100],
        ['rate' => 10, 'amount' => 100],
        ['rate' => 4, 'amount' => 100],
    ]);

    expect(array_column($result['lines'], 'gross'))->toBe(['33.33', '33.33', '33.34'])
        ->and(bcadd(bcadd($result['lines'][0]['gross'], $result['lines'][1]['gross'], 2), $result['lines'][2]['gross'], 2))->toBe('100.00');
});

it('refuses a ventilation without purchases, with negative purchases or with a zero rate without nature', function (): void {
    $service = app(ReceiptsRegisterService::class);

    expect(fn () => $service->ventilate('100.00', [['rate' => 22, 'amount' => 0]]))->toThrow(ItalianVatException::class)
        ->and(fn () => $service->ventilate('100.00', [['rate' => 22, 'amount' => -5]]))->toThrow(ItalianVatException::class)
        ->and(fn () => $service->ventilate('100.00', [['rate' => 0, 'amount' => 50]]))->toThrow(ItalianVatException::class)
        ->and(fn () => $service->ventilate('0.00', [['rate' => 22, 'amount' => 50]]))->toThrow(ItalianVatException::class);

    $exempt = $service->ventilate('100.00', [['rate' => 0, 'amount' => 50, 'nature' => 'N4']]);

    expect($exempt['lines'][0]['nature'])->toBe('N4')
        ->and($exempt['total_vat'])->toBe('0.00');
});
