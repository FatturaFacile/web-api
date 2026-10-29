<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VatCodeTranslation extends Model
{
    use HasFactory;

    protected $table = 'vat_code_translations';

    protected $fillable = [
        'vat_code_id',
        'locale',
        'name',
        'legal_description',
    ];

    /**
     * @return BelongsTo<VatCode, $this>
     */
    public function vatCode(): BelongsTo
    {
        return $this->belongsTo(VatCode::class, 'vat_code_id');
    }
}
