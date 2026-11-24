<?php

declare(strict_types=1);

namespace Modules\ItalianPayroll\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Append-only receipt of a payslip opening: who, from which address, when. Never updated or deleted.
 *
 * @property string $id
 * @property string $distribution_id
 * @property string $employee_id
 * @property string $document_kind
 * @property string|null $ip_address
 * @property bool $biometric_confirmed
 * @property CarbonImmutable $occurred_at
 */
final class PayslipDownloadAudit extends BaseEnterpriseModel
{
    protected $table = 'ipp_download_audits';

    protected static function booted(): void
    {
        self::updating(static fn (): bool => false);
        self::deleting(static fn (): bool => false);
    }

    protected function casts(): array
    {
        return ['biometric_confirmed' => 'boolean', 'occurred_at' => 'immutable_datetime'];
    }
}
