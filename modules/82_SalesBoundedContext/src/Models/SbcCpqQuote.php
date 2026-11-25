<?php

declare(strict_types=1);

namespace Modules\SalesBoundedContext\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Modules\SalesBoundedContext\Domain\Enums\ElectronicSignatureStandard;

final class SbcCpqQuote extends BaseEnterpriseModel
{
    protected $table = 'sbc_cpq_quotes';

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'valid_until' => 'date',
            'lines' => 'array',
            'signature_audit_trail' => 'array',
            'signature_standard' => ElectronicSignatureStandard::class,
            'total_net' => 'decimal:2',
            'total_tax' => 'decimal:2',
            'total_gross' => 'decimal:2',
        ];
    }
}
