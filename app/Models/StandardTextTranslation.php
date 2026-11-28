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
class StandardTextTranslation extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory;

    protected $table = 'standard_text_translations';

    protected $fillable = [
        'tenant_id',
        'standard_text_id',
        'locale',
        'title',
        'body',
    ];

    /**
     * @return BelongsTo<StandardText, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(StandardText::class, 'standard_text_id');
    }
}
