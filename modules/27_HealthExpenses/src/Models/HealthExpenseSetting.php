<?php

declare(strict_types=1);

namespace Modules\HealthExpenses\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class HealthExpenseSetting extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'is_enabled', 'subject_type', 'owner_fiscal_code', 'region_code', 'asl_code', 'ssa_code'];

    /**
     * @var list<string>
     */
    protected $hidden = ['owner_fiscal_code'];

    protected function casts(): array
    {
        return ['is_enabled' => 'boolean', 'owner_fiscal_code' => 'encrypted'];
    }
}
