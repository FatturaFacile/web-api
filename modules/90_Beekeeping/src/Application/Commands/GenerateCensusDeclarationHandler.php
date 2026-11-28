<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

use Modules\Beekeeping\Models\CensusDeclaration;
use Modules\Beekeeping\Services\CensusService;

final class GenerateCensusDeclarationHandler
{
    public function __construct(private readonly CensusService $service) {}

    public function handle(GenerateCensusDeclarationCommand $command): CensusDeclaration
    {
        return $this->service->generate($command->year, $command->country);
    }
}
