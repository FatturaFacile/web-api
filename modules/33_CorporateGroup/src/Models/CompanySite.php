<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanySite extends BaseEnterpriseModel
{
    protected $table = 'company_sites';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
