<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Translation extends Model
{
    /** @use HasFactory<TranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'locale',
        'group',
        'key',
        'value',
    ];

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class)->withDefault();
    }
}
