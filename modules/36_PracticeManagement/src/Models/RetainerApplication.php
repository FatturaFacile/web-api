<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Models;

use Carbon\CarbonImmutable;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Enterprise\MultiTenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Part of a retainer applied to a fee note, on a date.
 *
 * @property string $id
 * @property string $retainer_id
 * @property string $fee_note_id
 * @property string $amount
 * @property CarbonImmutable $applied_on
 */
final class RetainerApplication extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'practice_retainer_applications';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['applied_on' => 'immutable_date', 'amount' => 'decimal:2'];
    }
}
