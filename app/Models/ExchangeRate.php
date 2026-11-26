<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DateOnly;
use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A rate of the exchange-rate table: 1 `source` (base currency) = `rate` `target` (quote currency) on `effective_date`.
 * A row without tenant is an official rate shared by every company; a row with a tenant is that company's manual or contract
 * rate. A rate is never edited: a correction is a new `version` and the superseded one points to it (`superseded_by`).
 * The currency engine lives in Modules\Treasury\Currency; this model is the shared storage the whole platform reads.
 *
 * @property string $id
 * @property string|null $tenant_id
 * @property string $source
 * @property string $target
 * @property string $rate
 * @property string $rate_type
 * @property string $rate_source
 * @property bool $is_derived
 * @property int $version
 * @property string|null $superseded_by
 * @property string|null $reason
 * @property string|null $contract_reference
 */
class ExchangeRate extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'exchange_rates';

    protected $fillable = [
        'source',
        'target',
        'rate',
        'effective_date',
        'rate_type',
        'rate_source',
        'is_derived',
        'version',
        'superseded_by',
        'reason',
        'contract_reference',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'effective_date' => DateOnly::class,
            'is_derived' => 'boolean',
            'version' => 'integer',
        ];
    }
}
