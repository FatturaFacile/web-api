<?php

declare(strict_types=1);

namespace Modules\Hospitality\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group booking: rooms held per room type for a group between a check-in and a check-out date. The rooms nobody picked
 * up are given back to the general availability on the release date, or at once when the block is released by hand.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $number
 * @property string $name
 * @property ?string $organizer
 * @property ?string $contact_id
 * @property string $status
 */
final class GroupBlock extends HospitalityRecord
{
    /** @var list<string> */
    public const array Statuses = ['open', 'released', 'cancelled'];

    protected $table = 'hosp_group_blocks';

    protected function casts(): array
    {
        return [
            'check_in_date' => 'date:Y-m-d',
            'check_out_date' => 'date:Y-m-d',
            'release_date' => 'date:Y-m-d',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<GroupBlockRoom, $this>
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(GroupBlockRoom::class, 'group_block_id');
    }

    /**
     * @return HasMany<Stay, $this>
     */
    public function stays(): HasMany
    {
        return $this->hasMany(Stay::class, 'group_block_id');
    }
}
