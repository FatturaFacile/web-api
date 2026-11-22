<?php

declare(strict_types=1);

namespace Modules\Invoicing\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;

/**
 * Prudential provision rate (percent) applied to the open amount of an aging bucket of the company.
 *
 * @property string $bucket_key
 * @property string $rate_percent
 */
final class ReceivableProvisionRate extends BaseEnterpriseModel
{
    protected $table = 'receivable_provision_rates';

    protected function casts(): array
    {
        return ['rate_percent' => 'decimal:2'];
    }
}
