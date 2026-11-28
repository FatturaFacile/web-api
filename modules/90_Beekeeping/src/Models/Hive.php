<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Beekeeping\Domain\ValueObjects\QueenMarking;

/**
 * A hive or nucleus with its queen and the colony figures of the last inspection.
 *
 * @property string $apiary_id
 * @property string $code
 * @property string $qr_token
 * @property string $hive_type
 * @property string $origin
 * @property string $status
 * @property bool $is_nucleus
 * @property int|null $queen_year
 * @property string|null $queen_marking_color
 * @property bool $queen_clipped
 * @property string|null $queen_genetic_line
 * @property string|null $queen_breeder
 * @property string|null $queen_pedigree_ref
 * @property string $queen_status
 * @property int $brood_frames
 * @property int $store_frames
 * @property int $supers_count
 * @property int $strength_score
 * @property string|null $temperament
 * @property Carbon|null $installed_on
 * @property string|null $notes
 * @property string|null $created_by
 * @property string|null $updated_by
 */
final class Hive extends BaseEnterpriseModel
{
    protected $table = 'bee_hives';

    protected static function booted(): void
    {
        // The marking colour is derived from the year of the queen, the QR token is the public identifier printed on the label.
        self::saving(static function (self $hive): void {
            $hive->queen_marking_color = $hive->queen_year === null ? null : QueenMarking::colorForYear((int) $hive->queen_year);

            if (blank($hive->qr_token)) {
                $hive->qr_token = Str::lower(Str::random(24));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_nucleus' => 'boolean',
            'queen_year' => 'integer',
            'queen_clipped' => 'boolean',
            'brood_frames' => 'integer',
            'store_frames' => 'integer',
            'supers_count' => 'integer',
            'strength_score' => 'integer',
            'installed_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Apiary, $this>
     */
    public function apiary(): BelongsTo
    {
        return $this->belongsTo(Apiary::class, 'apiary_id');
    }

    /**
     * @return HasMany<Inspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class, 'hive_id');
    }

    public function isProductive(): bool
    {
        return in_array($this->status, ['active', 'orphaned', 'queenless_cell'], true);
    }
}
