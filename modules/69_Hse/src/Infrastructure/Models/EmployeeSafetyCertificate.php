<?php

declare(strict_types=1);

namespace Modules\Hse\Infrastructure\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;
use Modules\Hse\Domain\Enums\CertificateStatus;
use Modules\Hse\Domain\Enums\CertificateType;

/**
 * Aggregate root: a training certificate or license of an employee. The status is derived from the expiry day.
 *
 * @property string $employee_id
 * @property CertificateType $certificate_type
 * @property string|null $certificate_number
 * @property string|null $training_provider_name
 * @property numeric-string $hours_completed
 * @property Carbon $issued_at
 * @property Carbon|null $expires_at
 * @property string|null $document_id
 * @property CertificateStatus $status
 * @property Carbon|null $revoked_at
 * @property int $version
 */
final class EmployeeSafetyCertificate extends BaseEnterpriseModel
{
    protected $table = 'hse_safety_certificates';

    protected function casts(): array
    {
        return [
            'certificate_type' => CertificateType::class,
            'status' => CertificateStatus::class,
            'issued_at' => 'date',
            'expires_at' => 'date',
            'revoked_at' => 'datetime',
            'hours_completed' => 'decimal:2',
            'version' => 'integer',
        ];
    }

    public function statusAt(CarbonImmutable $day): CertificateStatus
    {
        return CertificateStatus::at(
            $this->expires_at === null ? null : CarbonImmutable::parse($this->expires_at->toDateString()),
            $day,
            $this->revoked_at !== null || $this->status === CertificateStatus::Revoked,
        );
    }

    public function expiresAtImmutable(): ?CarbonImmutable
    {
        return $this->expires_at === null ? null : CarbonImmutable::parse($this->expires_at->toDateString());
    }
}
