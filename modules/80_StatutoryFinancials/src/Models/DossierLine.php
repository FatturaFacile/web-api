<?php

declare(strict_types=1);

namespace Modules\StatutoryFinancials\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * A normalized line of a statement of a dossier (the structured JSON on the dossier keeps the same figures for the preview).
 *
 * @property string $dossier_id
 * @property string $statement
 * @property string $line_code
 * @property string|null $parent_code
 * @property int $sort_order
 * @property bool $is_total
 * @property bool $is_rounding
 * @property string $exact_amount
 * @property string $amount
 * @property string|null $comparative_amount
 * @property array<int, mixed>|null $sources
 */
final class DossierLine extends BaseEnterpriseModel
{
    protected $table = 'sfs_dossier_lines';

    protected function casts(): array
    {
        return [
            'is_total' => 'boolean',
            'is_rounding' => 'boolean',
            'exact_amount' => 'decimal:4',
            'amount' => 'decimal:4',
            'comparative_amount' => 'decimal:4',
            'sort_order' => 'integer',
            'sources' => 'array',
        ];
    }
}
