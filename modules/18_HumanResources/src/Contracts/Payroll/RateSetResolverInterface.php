<?php

declare(strict_types=1);

namespace Modules\HumanResources\Contracts\Payroll;

use DateTimeInterface;
use Modules\HumanResources\DTOs\Payroll\RateSet;
use Modules\HumanResources\Exceptions\Payroll\MissingRateSetException;

interface RateSetResolverInterface
{
    /**
     * Resolves the rate set valid on the given date: the tenant's own set wins over the system default.
     *
     * @throws MissingRateSetException
     */
    public function resolve(DateTimeInterface $date, string $periodCode): RateSet;
}
