<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\FranchiseNetwork\Domain\Enums\CollectionScheme;

/**
 * SEPA SDD or LSV+ batch of the royalty invoices of a run, ready to be exported to the bank.
 *
 * @property string $run_id
 * @property CollectionScheme $scheme
 * @property string $status
 * @property string $total_amount
 * @property string $currency
 * @property Carbon $collection_date
 * @property list<array<string, mixed>> $items
 */
final class CollectionBatch extends BaseEnterpriseModel
{
    protected $table = 'frn_collection_batches';

    protected function casts(): array
    {
        return [
            'scheme' => CollectionScheme::class,
            'total_amount' => 'decimal:2',
            'collection_date' => 'date',
            'items' => 'array',
        ];
    }

    /**
     * @return BelongsTo<RoyaltyRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(RoyaltyRun::class, 'run_id');
    }
}
