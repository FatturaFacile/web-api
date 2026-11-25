<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Illuminate\Support\Facades\DB;
use Modules\StatutoryFinancials\Domain\Enums\DossierStatus;
use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\NotesWorkspace;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\DossierService;
use Modules\StatutoryFinancials\Services\NotesService;
use Modules\StatutoryFinancials\Services\StatementPersistence;

final class PopulateExplanatoryNotesTablesFromSubledgersHandler
{
    public function __construct(
        private readonly NotesService $notes,
        private readonly StatementPersistence $persistence,
        private readonly DossierService $dossiers,
    ) {}

    public function handle(PopulateExplanatoryNotesTablesFromSubledgersCommand $command): NotesWorkspace
    {
        $dossier = StatutoryDossier::query()->findOrFail($command->dossierId);
        $this->dossiers->assertEditable($dossier);

        if ($dossier->balance_sheet_lines === null) {
            throw new StatutoryFinancialsException('statements_not_generated', 422);
        }

        $bundle = $this->persistence->rehydrate($dossier);
        $workspace = $dossier->notes;
        $result = $this->notes->populate(
            $dossier,
            $bundle,
            $workspace?->quantitative_tables,
            $command->overwriteNarrative ? null : $workspace?->narrative_text_sections,
        );

        return DB::transaction(function () use ($dossier, $workspace, $result): NotesWorkspace {
            $workspace ??= new NotesWorkspace(['tenant_id' => $dossier->tenant_id, 'dossier_id' => $dossier->id]);
            $workspace->taxonomy_version = (string) config('statutory_financials.taxonomy.'.$dossier->accounting_standard->jurisdiction().'.version');
            $workspace->quantitative_tables = $result['tables'];
            $workspace->narrative_text_sections = $result['sections'];
            $workspace->sources = $result['sources'];
            $workspace->populated_at = now();
            $workspace->save();

            if ($dossier->status === DossierStatus::Reclassified) {
                $dossier->status = DossierStatus::NotesPopulated;
            }

            $dossier->validated_at = null;
            $dossier->save();

            return $workspace;
        });
    }
}
