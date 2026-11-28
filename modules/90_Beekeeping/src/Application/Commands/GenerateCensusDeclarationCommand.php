<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

final readonly class GenerateCensusDeclarationCommand
{
    public function __construct(public int $year, public string $country) {}
}
