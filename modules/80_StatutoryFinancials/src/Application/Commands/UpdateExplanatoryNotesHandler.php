<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Application\Commands;

use Modules\StatutoryFinancials\Exceptions\StatutoryFinancialsException;
use Modules\StatutoryFinancials\Models\NotesWorkspace;
use Modules\StatutoryFinancials\Models\StatutoryDossier;
use Modules\StatutoryFinancials\Services\DossierService;

final class UpdateExplanatoryNotesHandler
{
    public function __construct(private readonly DossierService $dossiers) {}

    public function handle(UpdateExplanatoryNotesCommand $command): NotesWorkspace
    {
        $dossier = StatutoryDossier::query()->findOrFail($command->dossierId);
        $this->dossiers->assertEditable($dossier);
        $workspace = $dossier->notes;

        if ($workspace === null) {
            throw new StatutoryFinancialsException('notes_not_populated', 422);
        }

        $tables = $workspace->quantitative_tables ?? [];

        foreach ($command->tables ?? [] as $name => $table) {
            $tables[$name] = array_merge($tables[$name] ?? [], $table, ['manual' => true, 'source' => 'manual']);
        }

        $sections = $workspace->narrative_text_sections ?? [];

        foreach ($command->sections ?? [] as $edit) {
            $found = false;

            foreach ($sections as $index => $section) {
                if ($section['key'] === $edit['key']) {
                    $sections[$index] = array_merge($section, ['body' => $edit['body'], 'title' => $edit['title'] ?? $section['title'], 'edited' => true]);
                    $found = true;
                }
            }

            if (! $found) {
                $sections[] = ['key' => $edit['key'], 'title' => $edit['title'] ?? $edit['key'], 'body' => $edit['body'], 'edited' => true];
            }
        }

        $workspace->quantitative_tables = $tables;
        $workspace->narrative_text_sections = $sections;
        $workspace->save();

        $dossier->validated_at = null;
        $dossier->save();

        return $workspace;
    }
}
