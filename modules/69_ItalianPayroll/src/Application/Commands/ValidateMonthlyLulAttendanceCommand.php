<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Commands;

final readonly class ValidateMonthlyLulAttendanceCommand
{
    /**
     * @param  array<string, string>  $fringeBenefits  employee id => taxable fringe benefit of the month
     */
    public function __construct(public string $periodId, public ?string $actorId, public array $fringeBenefits = []) {}
}
