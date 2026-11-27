<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Models;

use App\Domain\Audit\Concerns\HasImmutableAuditTrail;
use App\Domain\Audit\Contracts\AuditableEntityInterface;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Enterprise\MultiTenant\Concerns\BelongsToCompany;
use Illuminate\Support\Carbon;

/**
 * Persisted studio accounting export run (Abacus, Topal, Sage 50 Extra, TeamSystem, Zucchetti, Profis, DATEV).
 *
 * @property string $id
 * @property string $tenant_id
 * @property string|null $fiscal_year_id
 * @property string|null $fiscal_period_id
 * @property string $format_code
 * @property string|null $jurisdiction
 * @property Carbon|null $from_date
 * @property Carbon|null $to_date
 * @property bool $only_verified
 * @property bool $apply_transcoding
 * @property int $entries_count
 * @property int $lines_count
 * @property int $unmapped_accounts_count
 * @property string $total_debit
 * @property string $total_credit
 * @property string $currency
 * @property string $file_name
 * @property string $mime_type
 * @property array<string, mixed>|null $diagnostics_json
 * @property string|null $exported_by
 * @property Carbon|null $created_at
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
