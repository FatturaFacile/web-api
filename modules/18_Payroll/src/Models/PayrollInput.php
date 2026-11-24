<?php

declare(strict_types=1);

namespace Modules\Payroll\Models;

use Modules\Payroll\Exceptions\PayrollRunClosedException;

/**
 * A variable input of a month. Once the run of the month is closed its inputs are frozen with it.
 */
final class PayrollInput extends PayrollRecord
{
    protected $table = 'payroll_inputs';

    protected static function booted(): void
    {
        self::saving(static fn (self $input) => $input->assertPeriodIsOpen());
        self::deleting(static fn (self $input) => $input->assertPeriodIsOpen());
    }

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'quantity' => 'decimal:4',
            'rate' => 'decimal:4',
            'amount' => 'decimal:2',
        ];
    }

    private function assertPeriodIsOpen(): void
    {
        $periods = [[(int) $this->year, (int) $this->month]];

        if ($this->exists) {
            $periods[] = [(int) $this->getOriginal('year'), (int) $this->getOriginal('month')];
        }

        foreach ($periods as [$year, $month]) {
            if (PayrollRun::query()->where('year', $year)->where('month', $month)->where('status', PayrollRun::StatusFinal)->exists()) {
                throw PayrollRunClosedException::forPeriod($year, $month);
            }
        }
    }
}
