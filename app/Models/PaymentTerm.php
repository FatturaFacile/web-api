<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentTerm extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'days',
        'end_of_month',
        'discount_percent',
        'discount_days',
        'sdi_terms_code',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'discount_days' => 'integer',
            'discount_percent' => 'decimal:2',
            'end_of_month' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PaymentTermInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(PaymentTermInstallment::class);
    }

    /**
     * @return HasMany<PaymentTermTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(PaymentTermTranslation::class, 'payment_term_id');
    }

    protected function getTranslationModelClass(): string
    {
        return PaymentTermTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'payment_term_id';
    }
}
