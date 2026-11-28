<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $locale
 */
class ProductTranslation extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory;

    protected $table = 'product_translations';

    protected $fillable = [
        'tenant_id',
        'product_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<Product, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
