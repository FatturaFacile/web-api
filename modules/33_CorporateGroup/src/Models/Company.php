<?php

declare(strict_types=1);

namespace Modules\CorporateGroup\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Company extends BaseEnterpriseModel
{
    protected $table = 'companies';

    protected function casts(): array
    {
        return [
            'ownership_percentage' => 'decimal:2',
            'shares_master_data' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_company_id');
    }

    public function subsidiaries(): HasMany
    {
        return $this->hasMany(self::class, 'parent_company_id');
    }

    public function sites(): HasMany
    {
        return $this->hasMany(CompanySite::class, 'company_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CompanyMember::class, 'company_id');
    }

    public function accountingSettings(): HasOne
    {
        return $this->hasOne(CompanyAccountingSetting::class, 'company_id');
    }
}
