<?php

declare(strict_types=1);

namespace Modules\Intercompany\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Support\Carbon;

/**
 * An intercompany document of one entity and its mirror in the books of the counterparty (idempotent by key).
 *
 * @property Carbon $document_date
 * @property int $version
 * @property string $net_amount
 * @property string $tax_amount
 * @property string $gross_amount
 * @property string $synced_paid_amount
 * @property string $paid_variance
 * @property Carbon|null $created_at
 */
final class TransactionLink extends BaseEnterpriseModel
{
    protected $table = 'ic_transaction_links';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'net_amount' => 'decimal:4',
            'tax_amount' => 'decimal:4',
            'gross_amount' => 'decimal:4',
            'synced_paid_amount' => 'decimal:4',
            'paid_variance' => 'decimal:4',
            'document_date' => 'date',
            'version' => 'integer',
        ];
    }
}
