<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryMovementType extends Model
{
    use BelongsToTenant, HasFactory, HasTranslations, HasUuids;

    protected $table = 'inventory_movement_types';

    protected $fillable = [
        'tenant_id',
        'code',
        'direction',
        'affects_cogs',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'affects_cogs' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<InventoryMovementTypeTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(InventoryMovementTypeTranslation::class, 'movement_type_id');
    }

    protected function getTranslationModelClass(): string
    {
        return InventoryMovementTypeTranslation::class;
    }

    protected function getTranslationForeignKey(): string
    {
        return 'movement_type_id';
    }
}
