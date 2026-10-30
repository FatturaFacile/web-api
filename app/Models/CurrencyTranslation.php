<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'currency_translations';

    protected $fillable = [
        'tenant_id',
        'currency_id',
        'locale',
        'name',
        'subunit_name',
    ];

    /**
     * @return BelongsTo<Currency, $this>
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }
}
