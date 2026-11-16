<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rooms of one room type held by a group block, with the rate negotiated for the group when there is one.
 *
 * @property string $id
 * @property string $group_block_id
 * @property string $room_type_id
 * @property int $rooms_blocked
 * @property ?string $nightly_rate
 * @property GroupBlock $block
 */
final class GroupBlockRoom extends HospitalityRecord
{
    protected $table = 'hosp_group_block_rooms';

    protected function casts(): array
    {
        return ['rooms_blocked' => 'integer', 'nightly_rate' => 'decimal:2'];
    }

    /**
     * @return BelongsTo<GroupBlock, $this>
     */
    public function block(): BelongsTo
    {
        return $this->belongsTo(GroupBlock::class, 'group_block_id');
    }

    /**
     * @return BelongsTo<RoomType, $this>
     */
    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }
}
