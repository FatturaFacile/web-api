<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Application\Queries;

final readonly class GetTfrAccrualLedgerQuery
{
    /** @param  string|null  $foiPercent  the ISTAT FOI change used to project the yearly revaluation of the fund (e.g. '2.4') */
    public function __construct(public int $year, public ?string $legalEntityId = null, public ?string $foiPercent = null) {}
}
