<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LookupItemTranslation extends Model
{
    use HasFactory;

    protected $table = 'lookup_item_translations';

    protected $fillable = [
        'tenant_id',
        'lookup_item_id',
        'locale',
        'label',
        'short_label',
        'description',
    ];

    /**
     * @return BelongsTo<LookupItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(LookupItem::class, 'lookup_item_id');
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withDefault();
    }
}
