<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\Artifact;
use Modules\StatutoryFinancials\Models\DepositPackage;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Pdf\PdfAVerifier;
use Modules\StatutoryFinancials\Services\ArtifactStore;
use Modules\StatutoryFinancials\Services\DepositDocumentComposer;
use Modules\StatutoryFinancials\Services\StatementPersistence;
use Modules\StatutoryFinancials\Services\StatutoryGenerationService;
use Modules\StatutoryFinancials\Strategies\StatutoryStrategyResolver;

final class GeneratePdfADocumentsHandler
{
    private const array Fields = [
        'management_report' => 'management_report_pdf_a_id',
        'board_minutes' => 'board_minutes_pdf_a_id',
        'shareholders_minutes' => 'shareholders_minutes_pdf_a_id',
        'dossier' => 'dossier_pdf_a_id',
    ];

    public function __construct(
        private readonly StatementPersistence $persistence,
        private readonly DepositDocumentComposer $composer,
        private readonly PdfAVerifier $verifier,
        private readonly ArtifactStore $artifacts,
        private readonly StatutoryStrategyResolver $strategies,
        private readonly StatutoryGenerationService $generation,
    ) {}

    /**
     * @return array{package: DepositPackage, artifacts: list<Artifact>}
     */
    public function handle(GeneratePdfADocumentsCommand $command): array
    {
        $dossier = StatutoryDossier::query()->findOrFail($command->dossierId);

        if ($dossier->balance_sheet_lines === null) {
            throw new StatutoryFinancialsException('statements_not_generated', 422);
        }

        $bundle = $this->persistence->rehydrate($dossier);
        $profile = $this->strategies->for($dossier->accounting_standard)->pdfAProfile();
        $package = $dossier->package ?? new DepositPackage(['tenant_id' => $dossier->tenant_id, 'dossier_id' => $dossier->id]);
        $indicators = $package->financial_ratios_crisi_impresa ?? $this->generation->indicators($dossier);
        $notes = $dossier->notes !== null ? ['tables' => $dossier->notes->quantitative_tables ?? [], 'sections' => $dossier->notes->narrative_text_sections ?? []] : null;
        $meeting = $command->meeting + ['meeting_date' => now()->toDateString(), 'meeting_place' => '', 'chair' => '', 'secretary' => '', 'attendees' => []];
        $stored = [];

        foreach (array_unique($command->kinds) as $kind) {
            if (! isset(self::Fields[$kind])) {
                throw new StatutoryFinancialsException('pdfa_kind_unknown', 422, ['kind' => $kind]);
            }

            $bytes = match ($kind) {
                'management_report' => $this->composer->managementReport($dossier, $bundle, $indicators),
                'board_minutes' => $this->composer->minutes($dossier, $bundle, 'board', $meeting),
                'shareholders_minutes' => $this->composer->minutes($dossier, $bundle, 'shareholders', $meeting),
                default => $this->composer->dossier($dossier, $bundle, $dossier->cash_flow_statement, $notes, $indicators, $package->tebeni_validation_report),
            };

            $verification = $this->verifier->verify($bytes, $profile);

            if (! $verification['compliant']) {
                throw new StatutoryFinancialsException('pdfa_not_compliant', 500, ['kind' => $kind]);
            }

            $fileName = Str::slug($kind.'-'.$dossier->accounting_standard->value.'-'.($dossier->fiscalYear->year_code ?? 'year')).'.pdf';
            $stored[$kind] = $this->artifacts->store($dossier, 'pdfa_'.$kind, $fileName, 'application/pdf', $bytes, $profile, $verification);
        }

        DB::transaction(function () use ($package, $stored): void {
            foreach ($stored as $kind => $artifact) {
                $package->{self::Fields[$kind]} = $artifact->id;
            }

            $package->save();
        });

        return ['package' => $package, 'artifacts' => array_values($stored)];
    }
}
