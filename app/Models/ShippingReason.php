<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reason for the transport printed on a delivery note (causale del trasporto), translated per locale.
 */
class ShippingReason extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory, HasTranslations, HasUuids, SoftDeletes;

    protected $table = 'shipping_reasons';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return HasMany<ShippingReasonTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(ShippingReasonTranslation::class, 'shipping_reason_id');
    }

    protected function getTranslationModelClass(): string
    {
        return ShippingReasonTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'shipping_reason_id';
    }
}
