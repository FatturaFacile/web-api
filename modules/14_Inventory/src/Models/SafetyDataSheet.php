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

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @return HasMany<SafetyDataSheetVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(SafetyDataSheetVersion::class, 'safety_data_sheet_id')->orderByDesc('version_number');
    }

    /**
     * @return BelongsTo<SafetyDataSheetVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(SafetyDataSheetVersion::class, 'current_version_id');
    }
}
