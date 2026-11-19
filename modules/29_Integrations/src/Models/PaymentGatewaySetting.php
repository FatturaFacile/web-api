<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property list<string>|null $allowed_payment_methods
 */
class PaymentGatewaySetting extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'provider', 'credentials', 'allowed_payment_methods'];

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array', 'allowed_payment_methods' => 'array'];
    }
}
