<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\Foundation\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One VIES consultation, kept for fiscal auditability.
 *
 * @property string $id
 * @property string $country_code
 * @property string $vat_number
 * @property string $status
 * @property bool|null $is_valid
 * @property string|null $trader_name
 * @property string|null $trader_address
 * @property string|null $request_identifier
 * @property string|null $error_code
 * @property Carbon $validated_at
 */
final class VatValidationLog extends Model
{
    use BelongsToTenant, HasUuidV7;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id', 'tenant_id', 'created_at', 'updated_at'];

    protected function casts(): array
    {
        return ['is_valid' => 'boolean', 'validated_at' => 'datetime'];
    }
}
