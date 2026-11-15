<?php

declare(strict_types=1);

namespace Modules\Hospitality\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\Tenancy\TenantClock;
use App\Services\Tenancy\TenantContext;
use App\Support\Database\TransactionLock;
use Illuminate\Support\Facades\DB;
use Modules\Hospitality\Models\GroupBlock;
use Modules\Hospitality\Models\GroupBlockRoom;
use Modules\Hospitality\Models\RoomType;
use Modules\Hospitality\Models\Stay;

/**
 * Group bookings: a block of rooms per room type held for a group between two dates, with a release date after which the
 * rooms nobody picked up go back to the general availability. Stays are picked up from the block through the lodging
 * service, so they follow the same availability and folio rules as any other stay.
 */
final class GroupBookingService
{
    public function __construct(private readonly LodgingService $lodging, private readonly TenantClock $clock) {}

    /**
     * Opens a block. Every room type must have the blocked rooms free over the whole interval.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): GroupBlock
    {
        $rooms = $data['rooms'];
        unset($data['rooms']);

        return DB::transaction(function () use ($data, $rooms): GroupBlock {
            TransactionLock::acquire('hosp_stays:'.app(TenantContext::class)->getTenantId());

            $block = new GroupBlock;
            $block->fill([...$data, 'number' => $this->nextNumber(), 'status' => 'open']);
            $block->save();

            $this->replaceRooms($block, $rooms);

            return $block->load('rooms');
        });
    }

    /**
     * Changes an open block. `rooms`, when given, is the full list of room types held: a type left out is dropped, and a
     * type never holds fewer rooms than were already picked up.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(GroupBlock $block, array $data): GroupBlock
    {
        return DB::transaction(function () use ($block, $data): GroupBlock {
            TransactionLock::acquire('hosp_stays:'.$block->tenant_id);
            $locked = GroupBlock::query()->lockForUpdate()->findOrFail($block->id);
            $this->assertOpen($locked);

            $rooms = $data['rooms'] ?? null;
            unset($data['rooms']);

            if (isset($data['release_date']) && $data['release_date'] > $locked->check_in_date->toDateString()) {
                throw new BusinessRuleException('The release date cannot be after the check-in date of the block.');
            }

            $locked->update($data);

            if ($rooms !== null) {
                $this->replaceRooms($locked, $rooms);
            }

            return $locked->load('rooms');
        });
    }

    /**
     * Gives the unpicked rooms back to the general availability now; the stays already picked up stay as they are.
     */
    public function release(GroupBlock $block): GroupBlock
    {
        return DB::transaction(function () use ($block): GroupBlock {
            $locked = GroupBlock::query()->lockForUpdate()->findOrFail($block->id);
            $this->assertOpen($locked);
            $locked->update(['status' => 'released', 'released_at' => now()]);

            return $locked;
        });
    }

    /**
     * Moves the dates of an open block. The stays already picked up must still fall inside the new dates and every room
     * type must have the rooms not picked up free over the new interval. The release date is kept unless one is given.
     *
     * @param  array{check_in_date: string, check_out_date: string, release_date?: string|null}  $data
     */
    public function changeDates(GroupBlock $block, array $data): GroupBlock
    {
        return DB::transaction(function () use ($block, $data): GroupBlock {
            TransactionLock::acquire('hosp_stays:'.$block->tenant_id);
            $locked = GroupBlock::query()->lockForUpdate()->findOrFail($block->id);
            $this->assertOpen($locked);

            $from = $data['check_in_date'];
            $to = $data['check_out_date'];
            $release = $data['release_date'] ?? $locked->release_date->toDateString();

            if ($release > $from) {
                throw new BusinessRuleException('The release date cannot be after the check-in date of the block.');
            }

            $outside = Stay::query()->where('group_block_id', $locked->id)->whereIn('status', Stay::Holding)
                ->where(static fn ($query) => $query->where('check_in_date', '<', $from)->orWhere('check_out_date', '>', $to))->count();

            if ($outside > 0) {
                throw new BusinessRuleException('Stays already picked up fall outside the new dates of the block.');
            }

            $locked->fill(['check_in_date' => $from, 'check_out_date' => $to, 'release_date' => $release]);
            $this->assertRoomsFree($locked);
            $locked->save();

            return $locked->load('rooms');
        });
    }

    /**
     * Gives back the rooms of every open block whose release date has passed. Returns the number of blocks released.
     */
    public function releaseExpired(): int
    {
        $today = $this->clock->today()->toDateString();
        $released = 0;

        GroupBlock::query()->where('status', 'open')->where('release_date', '<', $today)->orderBy('release_date')->each(function (GroupBlock $block) use (&$released): void {
            DB::transaction(function () use ($block, &$released): void {
                $locked = GroupBlock::query()->lockForUpdate()->find($block->id);

                if ($locked !== null && $locked->status === 'open') {
                    $locked->update(['status' => 'released', 'released_at' => now()]);
                    $released++;
                }
            });
        });

        return $released;
    }

    /**
     * Cancels the block; the stays picked up from it remain ordinary bookings.
     */
    public function cancel(GroupBlock $block): GroupBlock
    {
        return DB::transaction(function () use ($block): GroupBlock {
            $locked = GroupBlock::query()->lockForUpdate()->findOrFail($block->id);

            if ($locked->status === 'cancelled') {
                throw new BusinessRuleException('The group block is already cancelled.');
            }

            $locked->update(['status' => 'cancelled']);

            return $locked;
        });
    }

    /**
     * Books a stay out of the block, by default for the dates of the block.
     *
     * @param  array<string, mixed>  $data
     */
    public function bookStay(GroupBlock $block, array $data): Stay
    {
        return $this->lodging->book([
            ...$data,
            'check_in_date' => $data['check_in_date'] ?? $block->check_in_date->toDateString(),
            'check_out_date' => $data['check_out_date'] ?? $block->check_out_date->toDateString(),
            'group_block_id' => $block->id,
        ]);
    }

    /**
     * Pickup of the block: per room type the rooms blocked, picked up and still to pick up.
     *
     * @return list<array{room_type_id: string, rooms_blocked: int, picked_up: int, remaining: int, nightly_rate: ?string}>
     */
    public function pickup(GroupBlock $block): array
    {
        $picked = Stay::query()->where('group_block_id', $block->id)->whereIn('status', Stay::Holding)->selectRaw('room_type_id, count(*) as picked')->groupBy('room_type_id')->pluck('picked', 'room_type_id');

        return $block->rooms()->get()->map(static fn (GroupBlockRoom $room): array => [
            'room_type_id' => $room->room_type_id,
            'rooms_blocked' => (int) $room->rooms_blocked,
            'picked_up' => (int) ($picked[$room->room_type_id] ?? 0),
            'remaining' => max(0, (int) $room->rooms_blocked - (int) ($picked[$room->room_type_id] ?? 0)),
            'nightly_rate' => $room->nightly_rate === null ? null : (string) $room->nightly_rate,
        ])->values()->all();
    }

    /**
     * Whether the block is open but its release date has passed: the unpicked rooms are already back on sale.
     */
    public function isExpired(GroupBlock $block): bool
    {
        return $block->status === 'open' && $block->release_date->toDateString() < $this->clock->today()->toDateString();
    }

    /**
     * @param  list<array{room_type_id: string, rooms_blocked: int, nightly_rate?: mixed}>  $rooms
     */
    private function replaceRooms(GroupBlock $block, array $rooms): void
    {
        $from = $block->check_in_date->toDateString();
        $to = $block->check_out_date->toDateString();
        $wanted = collect($rooms)->keyBy('room_type_id');

        foreach ($block->rooms()->get() as $existing) {
            if (! $wanted->has($existing->room_type_id)) {
                $picked = Stay::query()->where('group_block_id', $block->id)->where('room_type_id', $existing->room_type_id)->whereIn('status', Stay::Holding)->count();

                if ($picked > 0) {
                    throw new BusinessRuleException('A room type with stays picked up cannot be dropped from the block.');
                }

                $existing->delete();
            }
        }

        foreach ($wanted as $roomTypeId => $row) {
            $type = RoomType::query()->findOrFail($roomTypeId);
            $picked = Stay::query()->where('group_block_id', $block->id)->where('room_type_id', $roomTypeId)->whereIn('status', Stay::Holding)->count();

            if ((int) $row['rooms_blocked'] < $picked) {
                throw new BusinessRuleException("{$picked} rooms of this room type are already picked up: the block cannot hold fewer.");
            }

            $free = $this->lodging->availability($type, $from, $to, null, $block->id)['available'];

            if ((int) $row['rooms_blocked'] - $picked > $free) {
                throw new BusinessRuleException("Only {$free} rooms of this room type are free to block for the whole interval.");
            }

            GroupBlockRoom::query()->updateOrCreate(
                ['group_block_id' => $block->id, 'room_type_id' => $roomTypeId],
                ['rooms_blocked' => (int) $row['rooms_blocked'], 'nightly_rate' => $row['nightly_rate'] ?? null],
            );
        }
    }

    private function assertRoomsFree(GroupBlock $block): void
    {
        $from = $block->check_in_date->toDateString();
        $to = $block->check_out_date->toDateString();

        foreach ($block->rooms()->get() as $room) {
            $picked = Stay::query()->where('group_block_id', $block->id)->where('room_type_id', $room->room_type_id)->whereIn('status', Stay::Holding)->count();
            $free = $this->lodging->availability(RoomType::query()->findOrFail($room->room_type_id), $from, $to, null, $block->id)['available'];

            if ((int) $room->rooms_blocked - $picked > $free) {
                throw new BusinessRuleException("Only {$free} rooms of a blocked room type are free over the new dates.");
            }
        }
    }

    private function assertOpen(GroupBlock $block): void
    {
        if ($block->status !== 'open') {
            throw new BusinessRuleException("The group block is {$block->status}: only an open block can change.");
        }
    }

    private function nextNumber(): string
    {
        $year = now()->format('Y');
        $last = GroupBlock::query()->where('number', 'like', "GRP-{$year}-%")->max('number');

        return sprintf('GRP-%s-%04d', $year, $last === null ? 1 : ((int) substr((string) $last, -4)) + 1);
    }
}
