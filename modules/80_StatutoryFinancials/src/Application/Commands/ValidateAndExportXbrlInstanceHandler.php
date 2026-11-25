<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\StatutoryFinancials\Domain\Enums\CashFlowMethod;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\DepositPackage;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\ArtifactStore;
use Modules\StatutoryFinancials\Services\LineCatalogRepository;
use Modules\StatutoryFinancials\Services\StatementPersistence;
use Modules\StatutoryFinancials\Services\XbrlInstanceBuilder;
use Modules\StatutoryFinancials\Services\XbrlValidationService;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;

final class ValidateAndExportXbrlInstanceHandler
{
    public function __construct(
        private readonly StatementPersistence $persistence,
        private readonly XbrlInstanceBuilder $builder,
        private readonly XbrlValidationService $validator,
        private readonly ArtifactStore $artifacts,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly LineCatalogRepository $catalogs,
    ) {}

    public function handle(ValidateAndExportXbrlInstanceCommand $command): DepositPackage
    {
        $dossier = StatutoryDossier::query()->findOrFail($command->dossierId);

        if ($dossier->balance_sheet_lines === null) {
            throw new StatutoryFinancialsException('statements_not_generated', 422);
        }

        $bundle = $this->persistence->rehydrate($dossier);
        $strategy = $this->strategies->for($dossier->accounting_standard);
        $profile = $strategy->xbrlProfile($dossier->accounting_standard);
        $cashFlow = $dossier->cash_flow_statement;
        $cashFlowCatalog = $cashFlow !== null ? $this->catalogs->catalog($dossier->accounting_standard, 'cash_flow', (string) ($cashFlow['method'] ?? CashFlowMethod::Indirect->value)) : null;
        $notes = $dossier->notes?->quantitative_tables;
        $notesRequired = $strategy->noteStructure($dossier->accounting_standard)['sections'] !== [];

        $xml = $this->builder->build($dossier, $bundle, $profile, $cashFlow, $cashFlowCatalog, $notes);
        $report = $this->validator->validate($xml, $profile, $bundle, $cashFlow, $notes, $notesRequired, $dossier->locale);

        $year = $dossier->fiscalYear;
        $fileName = Str::slug($dossier->accounting_standard->value.'-'.($year->year_code ?? 'year')).'.xbrl';
        $artifact = $this->artifacts->store($dossier, 'xbrl_instance', $fileName, 'application/xml', $xml);

        return DB::transaction(function () use ($dossier, $artifact, $report): DepositPackage {
            $package = $dossier->package ?? new DepositPackage(['tenant_id' => $dossier->tenant_id, 'dossier_id' => $dossier->id]);
            $package->generated_xbrl_instance_file_id = $artifact->id;
            $package->tebeni_validation_report = $report;
            $package->validation_status = $report['status'];
            $package->exported_at = now();
            $package->save();

            if ($dossier->isEditable() && $report['status'] !== 'invalid') {
                $dossier->status = DossierStatus::Validated;
                $dossier->validated_at = now();
                $dossier->save();
            }

            return $package;
        });
    }
}
