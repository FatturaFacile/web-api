<?php

declare(strict_types=1);

namespace Modules\SubscriptionBilling\Models;

use Enterprise\Foundation\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;

/**
 * Public statistical series (ISTAT FOI for Italy, BFS LIK for Switzerland). Shared across tenants.
 */
final class InflationIndex extends Model
{
    use HasUuidV7;

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
