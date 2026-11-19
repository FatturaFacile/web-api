<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TaricCodeLevel;
use App\Models\Concerns\TracksAuditUsers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Global (non tenant-scoped) catalog of HS / CN / TARIC customs codes.
 *
 * @property TaricCodeLevel $level
 */
class CustomsTaricCode extends Model
{
    use HasUuids, TracksAuditUsers;

    protected $fillable = [
        'code',
        'level',
        'chapter',
        'description',
        'supplementary_unit',
        'duty_rate_notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'level' => TaricCodeLevel::class,
            'description' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @param  Builder<CustomsTaricCode>  $query
     * @return Builder<CustomsTaricCode>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Search by code prefix or by any localized description.
     *
     * @param  Builder<CustomsTaricCode>  $query
     * @return Builder<CustomsTaricCode>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(trim($term)));

        // Casting the JSON column to text keeps the query portable across SQLite and PostgreSQL.
        return $query->where(function (Builder $q) use ($escaped): void {
            $q->whereRaw("code LIKE ? ESCAPE '!'", [$escaped.'%'])
                ->orWhereRaw("LOWER(CAST(description AS TEXT)) LIKE ? ESCAPE '!'", ['%'.$escaped.'%']);
        });
    }
}
