<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovementTypeTranslation extends Model
{
    use HasFactory;

    protected $table = 'inventory_movement_type_translations';

    protected $fillable = [
        'movement_type_id',
        'locale',
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<InventoryMovementType, $this>
     */
    public function movementType(): BelongsTo
    {
        return $this->belongsTo(InventoryMovementType::class, 'movement_type_id');
    }
}
