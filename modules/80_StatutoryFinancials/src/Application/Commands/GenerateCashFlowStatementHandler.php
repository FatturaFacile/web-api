<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\StatutoryGenerationService;

final class GenerateCashFlowStatementHandler
{
    public function __construct(private readonly StatutoryGenerationService $generation) {}

    public function handle(GenerateCashFlowStatementCommand $command): StatutoryDossier
    {
        return $this->generation->generateCashFlow(
            StatutoryDossier::query()->findOrFail($command->dossierId),
            $command->method !== null ? CashFlowMethod::from($command->method) : null,
        );
    }
}
