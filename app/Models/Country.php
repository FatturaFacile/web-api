<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Country extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'countries';

    protected $fillable = [
        'iso_code_2',
        'iso_code_3',
        'numeric_code',
        'phone_prefix',
        'is_eu_member',
        'is_sepa_member',
        'currency_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_eu_member' => 'boolean',
            'is_sepa_member' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CountryTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(CountryTranslation::class, 'country_id');
    }

    /**
     * @return HasMany<Region, $this>
     */
    public function regions(): HasMany
    {
        return $this->hasMany(Region::class, 'country_id');
    }

    protected function getTranslationModelClass(): string
    {
        return CountryTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'country_id';
    }
}
