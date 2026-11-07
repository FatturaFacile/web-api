<?php

declare(strict_types=1);

namespace Modules\Inventory\Models;

use App\Models\Product;
use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SafetyDataSheet extends BaseEnterpriseModel
{
    protected $table = 'safety_data_sheets';

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(SafetyDataSheetVersion::class, 'safety_data_sheet_id')->orderByDesc('version_number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SafetyDataSheetVersion::class, 'current_version_id');
    }
}
