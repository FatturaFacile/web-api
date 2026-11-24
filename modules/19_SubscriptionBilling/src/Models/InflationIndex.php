<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Monthly value of an inflation series (ISTAT FOI for Italy, BFS LIK for Switzerland) of a company.
 *
 * @property Carbon $reference_month
 */
final class InflationIndex extends Model
{
    use BelongsToTenant, HasUuidV7;

    public const ISTAT_FOI = 'istat_foi';

    public const LIK = 'lik';

    public const TYPES = [self::ISTAT_FOI, self::LIK];

    protected $table = 'sub_inflation_indices';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date',
            'index_value' => 'decimal:4',
        ];
    }
}
