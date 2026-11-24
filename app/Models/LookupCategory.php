<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Enterprise\MultiTenant\Concerns\AllowsGlobalRecords;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LookupCategory extends Model
{
    use AllowsGlobalRecords, BelongsToTenant, HasFactory, HasUuids;

    protected $table = 'lookup_categories';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_system',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * @return HasMany<LookupItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(LookupItem::class, 'category_id');
    }
}
