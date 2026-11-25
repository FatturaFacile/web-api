<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Queries;

use Modules\StatutoryFinancials\Domain\Enums\AccountingStandard;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\FinancialSnapshotBuilder;
use Modules\StatutoryFinancials\Services\StatutoryBalanceBuilder;
use Modules\StatutoryFinancials\Services\TextTemplateService;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;

final class CheckEligibilityHandler
{
    public function __construct(
        private readonly StatutoryBalanceBuilder $balances,
        private readonly FinancialSnapshotBuilder $snapshots,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly TextTemplateService $templates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(CheckEligibilityQuery $query): array
    {
        $dossier = StatutoryDossier::query()->findOrFail($query->dossierId);
        $chosen = $query->standard !== null ? AccountingStandard::from($query->standard) : $dossier->accounting_standard;
        $probe = $dossier->replicate();
        $probe->id = $dossier->id;
        $probe->accounting_standard = $chosen;

        $strategy = $this->strategies->for($chosen);
        $bundle = $this->balances->build($probe);
        $metrics = $this->snapshots->entityMetrics($probe, $bundle);
        $result = $strategy->evaluateEligibility($metrics, $chosen)->toArray();
        $locale = $dossier->locale;
        $result['message'] = $this->templates->render($result['message_key'], $locale, $result['limits']);
        $result['warnings'] = array_map(fn (array $warning): array => $warning + ['message' => $this->templates->render($warning['code'], $locale, $warning['params'])['body']], $result['warnings']);
        $result['metrics'] = $metrics->toArray();
        $result['cash_flow_required'] = $strategy->cashFlowRequired($chosen, $metrics);

        return $result;
    }
}
