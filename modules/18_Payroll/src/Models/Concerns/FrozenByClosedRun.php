<?php

declare(strict_types=1);

namespace Modules\Payroll\Models\Concerns;

use Modules\Payroll\Exceptions\PayrollRunClosedException;
use Modules\Payroll\Models\PayrollRun;

/**
 * What belongs to a closed run cannot be created, changed or deleted.
 */
trait FrozenByClosedRun
{
    public static function bootFrozenByClosedRun(): void
    {
        static::saving(static fn (self $record) => $record->assertRunIsOpen());
        static::deleting(static fn (self $record) => $record->assertRunIsOpen());
    }

    abstract protected function owningRun(): ?PayrollRun;

    private function assertRunIsOpen(): void
    {
        $run = $this->owningRun();

        if ($run?->isFinal()) {
            throw PayrollRunClosedException::forPeriod((int) $run->year, (int) $run->month);
        }
    }
}
