<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CompanyMember extends BaseEnterpriseModel
{
    protected $table = 'company_user';

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
