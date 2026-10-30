<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VatCodeTranslation extends Model
{
    use BelongsToTenant, HasFactory;

    protected $table = 'vat_code_translations';

    protected $fillable = [
        'tenant_id',
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
