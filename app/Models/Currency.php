<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Currency extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'currencies';

    protected $fillable = [
        'code',
        'name',
        'symbol',
        'precision',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'precision' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CurrencyTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CurrencyTranslation::class, 'currency_id');
    }

    protected function getTranslationModelClass(): string
    {
        return CurrencyTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'currency_id';
    }
}
