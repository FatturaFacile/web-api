<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentMethod extends Model
{
    use HasFactory, HasTranslations, HasUuids;

    protected $table = 'payment_methods';

    protected $fillable = [
        'code',
        'is_electronic',
        'requires_iban',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_electronic' => 'boolean',
            'requires_iban' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PaymentMethodTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(PaymentMethodTranslation::class, 'payment_method_id');
    }

    protected function getTranslationModelClass(): string
    {
        return PaymentMethodTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'payment_method_id';
    }
}
