<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;

/**
 * Persisted studio accounting export run (Abacus, Topal, Sage 50 Extra, TeamSystem, Zucchetti, Profis, DATEV).
 */
final class FiduciaryExportRun extends BaseEnterpriseModel implements AuditableEntityInterface
{
    use BelongsToCompany;
    use HasImmutableAuditTrail;

    protected $table = 'fiduciary_export_runs';

    protected $hidden = ['content_payload'];

    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'only_verified' => 'boolean',
            'apply_transcoding' => 'boolean',
            'entries_count' => 'integer',
            'lines_count' => 'integer',
            'unmapped_accounts_count' => 'integer',
            'total_debit' => 'decimal:4',
            'total_credit' => 'decimal:4',
            'diagnostics_json' => 'array',
        ];
    }
}
