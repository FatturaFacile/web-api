<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Models;

use Enterprise\Foundation\Models\BaseEnterpriseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Modules\IndustrialLaundry\Domain\Enums\ArticleType;
use Modules\IndustrialLaundry\Domain\Enums\GarmentEventType;
use Modules\IndustrialLaundry\Domain\Enums\LifecycleState;

/**
 * One step in the history of a garment: a scan, an assignment, a PPE decision or a billing charge.
 *
 * @property string $garment_id
 * @property string|null $scan_session_id
 * @property string|null $contact_id
 * @property ArticleType $article_type
 * @property GarmentEventType $event_type
 * @property LifecycleState|null $from_state
 * @property LifecycleState|null $to_state
 * @property int $wash_cycles_count
 * @property string|null $note
 * @property Carbon $occurred_at
 */
final class GarmentEvent extends BaseEnterpriseModel
{
    protected $table = 'ilr_garment_events';

    protected function casts(): array
    {
        return [
            'article_type' => ArticleType::class,
            'event_type' => GarmentEventType::class,
            'from_state' => LifecycleState::class,
            'to_state' => LifecycleState::class,
            'wash_cycles_count' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Garment, $this>
     */
    public function garment(): BelongsTo
    {
        return $this->belongsTo(Garment::class, 'garment_id');
    }
}
