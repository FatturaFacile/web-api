<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Models\DepositPackage;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\StatutoryGenerationService;

final class ComputeFinancialRatiosHandler
{
    public function __construct(private readonly StatutoryGenerationService $generation) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(ComputeFinancialRatiosCommand $command): array
    {
        $dossier = StatutoryDossier::query()->findOrFail($command->dossierId);
        $result = $this->generation->indicators($dossier, $command->whatIf);

        if ($command->persist) {
            $package = $dossier->package ?? new DepositPackage(['tenant_id' => $dossier->tenant_id, 'dossier_id' => $dossier->id]);
            $package->financial_ratios_crisi_impresa = $result;
            $package->save();
        }

        return $result;
    }
}
