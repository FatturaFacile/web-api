<?php

declare(strict_types=1);

namespace Modules\Treasury\Actions;

use Modules\Treasury\Contracts\ForeignExchangeRiskServiceInterface;
use Modules\Treasury\Models\FxRevaluation;

final readonly class PerformFxRevaluationAction
{
    public function __construct(
        private ForeignExchangeRiskServiceInterface $fxService
    ) {}

    public function execute(
        string $tenantId,
        string $currency,
        string $periodEndDate,
        float $spotRate,
        ?string $companyId = null,
        ?string $userId = null
    ): FxRevaluation {
        return $this->fxService->performFxRevaluation($tenantId, $currency, $periodEndDate, $spotRate, $companyId, $userId);
    }
}
