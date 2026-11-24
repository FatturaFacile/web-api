<?php

declare(strict_types=1);

namespace Modules\ItalianVat\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Certificazione Unica of a recipient of fees and commissions for a year.
 *
 * @property int $year
 * @property string $supplier_id
 * @property string $recipient_name
 * @property string|null $recipient_tax_code
 * @property string|null $recipient_vat_number
 * @property string $recipient_kind
 * @property string $reason_code
 * @property string $gross_amount
 * @property string $non_taxable_amount
 * @property string $taxable_amount
 * @property string $withheld_amount
 * @property string $contributions_amount
 * @property int $payments_count
 * @property string $status
 * @property array<int, array<string, mixed>>|null $validation_errors
 * @property CarbonImmutable|null $issued_at
 */
final class CuCertification extends BaseEnterpriseModel
{
    protected $table = 'it_cu_certifications';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'gross_amount' => 'decimal:2',
            'non_taxable_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'withheld_amount' => 'decimal:2',
            'contributions_amount' => 'decimal:2',
            'payments_count' => 'integer',
            'validation_errors' => 'array',
            'issued_at' => 'immutable_datetime',
        ];
    }
}
