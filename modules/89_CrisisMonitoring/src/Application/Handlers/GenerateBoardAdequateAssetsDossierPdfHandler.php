<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Application\Handlers;

use Carbon\CarbonImmutable;
use Modules\CrisisMonitoring\Application\Commands\GenerateBoardAdequateAssetsDossierPdfCommand;
use Modules\CrisisMonitoring\Application\Services\CrisisEngine;
use Modules\CrisisMonitoring\Application\Services\CrisisTexts;
use Modules\CrisisMonitoring\Application\Services\DossierPdfBuilder;
use Modules\CrisisMonitoring\Application\Services\StressTestSummary;
use Modules\CrisisMonitoring\Domain\Stress\StressShock;

/**
 * Builds the Art. 2086 c.c. dossier PDF from the live records: the baseline and a fixed sensitivity table of stress scenarios.
 */
final class GenerateBoardAdequateAssetsDossierPdfHandler
{
    /**
     * The scenarios of the sensitivity table: label key and shock.
     *
     * @var list<array{0: string, 1: array<string, int|string>}>
     */
    public const array Scenarios = [
        ['crisisApi.stress.baseline', []],
        ['crisisApi.stress.revenue_10', ['revenue_drop_percent' => 10]],
        ['crisisApi.stress.revenue_20', ['revenue_drop_percent' => 20]],
        ['crisisApi.stress.dso_30', ['dso_delay_days' => 30]],
        ['crisisApi.stress.dso_60', ['dso_delay_days' => 60]],
        ['crisisApi.stress.rate_100', ['interest_rate_bps' => 100]],
        ['crisisApi.stress.rate_250', ['interest_rate_bps' => 250]],
        ['crisisApi.stress.rate_500', ['interest_rate_bps' => 500]],
        ['crisisApi.stress.combined', ['revenue_drop_percent' => 20, 'dso_delay_days' => 30, 'interest_rate_bps' => 250]],
    ];

    public function __construct(
        private readonly CrisisEngine $engine,
        private readonly DossierPdfBuilder $builder,
    ) {}

    /**
     * @return string the binary PDF
     */
    public function handle(GenerateBoardAdequateAssetsDossierPdfCommand $command): string
    {
        $inputs = $this->engine->load($command->tenant, $command->asOf);
        $snapshot = $this->engine->evaluate($inputs);
        $sensitivity = [];

        foreach (self::Scenarios as [$labelKey, $shock]) {
            $sensitivity[] = ['label_key' => $labelKey, 'lite' => StressTestSummary::lite($this->engine->evaluate($inputs, StressShock::fromArray($shock)))];
        }

        $company = (string) ($command->tenant->legal_name ?: $command->tenant->name);

        return $this->builder->pdf($company, $snapshot, $sensitivity, CrisisTexts::locale($command->locale), CarbonImmutable::now());
    }
}
