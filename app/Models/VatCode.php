<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VatCode extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    protected $table = 'vat_codes';

    protected $fillable = [
        'tenant_id',
        'code',
        'country_iso',
        'rate',
        'nature',
        'is_split_payment',
        'is_reverse_charge',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rate' => 'decimal:2',
            'is_split_payment' => 'boolean',
            'is_reverse_charge' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<VatCodeTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(VatCodeTranslation::class, 'vat_code_id');
    }

    protected function getTranslationModelClass(): string
    {
        return VatCodeTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'vat_code_id';
    }
}
