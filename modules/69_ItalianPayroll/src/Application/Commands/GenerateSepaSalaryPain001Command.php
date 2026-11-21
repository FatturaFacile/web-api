<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Commands;

final readonly class GenerateSepaSalaryPain001Command
{
    /**
     * @param  array{name: string, iban: string, bic?: string|null}  $debtor
     */
    public function __construct(public string $periodId, public array $debtor, public string $executionDate) {}
}
