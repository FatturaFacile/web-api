<?php

declare(strict_types=1);

namespace Modules\Associations\Models;

/**
 * A CSV being imported: the analysed rows wait here, encrypted, for the confirmation of the preview.
 */
final class ImportBatch extends AssociationRecord
{
    public const string Preview = 'preview';

    public const string Committed = 'committed';

    protected $table = 'assoc_import_batches';

    protected $hidden = ['rows'];

    protected function casts(): array
    {
        return ['rows' => 'encrypted:array', 'summary' => 'array', 'committed_at' => 'datetime'];
    }
}
