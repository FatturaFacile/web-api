<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * ExplanatoryNotesAnhangWorkspace: quantitative tables and narrative sections of the Nota Integrativa (IT) or Anhang (CH).
 *
 * @property string $dossier_id
 * @property string $taxonomy_version
 * @property array<string, mixed>|null $quantitative_tables
 * @property list<array<string, mixed>>|null $narrative_text_sections
 * @property array<string, mixed>|null $sources
 * @property Carbon|null $populated_at
 */
final class NotesWorkspace extends BaseEnterpriseModel
{
    protected $table = 'sfs_notes_workspaces';

    protected function casts(): array
    {
        return [
            'quantitative_tables' => 'array',
            'narrative_text_sections' => 'array',
            'sources' => 'array',
            'populated_at' => 'datetime',
        ];
    }
}
