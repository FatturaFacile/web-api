<?php

declare(strict_types=1);

namespace Modules\Integrations\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PaymentGatewaySetting extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'provider', 'credentials'];

    /**
     * @var list<string>
     */
    protected $hidden = ['credentials'];

    protected function casts(): array
    {
        return ['credentials' => 'encrypted:array'];
    }
}
