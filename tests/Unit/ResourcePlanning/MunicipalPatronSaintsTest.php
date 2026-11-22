<?php

declare(strict_types=1);

use Modules\ResourcePlanning\Domain\Services\MunicipalPatronSaints;

it('knows the patron saint of the larger Italian municipalities, whatever the case', function (): void {
    expect(MunicipalPatronSaints::for('Milano'))->toBe(['month' => 12, 'day' => 7, 'name' => "Sant'Ambrogio"])
        ->and(MunicipalPatronSaints::for(' ROMA '))->toBe(['month' => 6, 'day' => 29, 'name' => 'Santi Pietro e Paolo'])
        ->and(MunicipalPatronSaints::for('Torino')['day'])->toBe(24)
        ->and(MunicipalPatronSaints::for('Varese'))->toBe(['month' => 5, 'day' => 8, 'name' => 'San Vittore']);
});

it('does not guess the patron saint of a municipality it does not know', function (): void {
    expect(MunicipalPatronSaints::for('Luino'))->toBeNull()
        ->and(MunicipalPatronSaints::for(''))->toBeNull()
        ->and(MunicipalPatronSaints::for(null))->toBeNull();
});
