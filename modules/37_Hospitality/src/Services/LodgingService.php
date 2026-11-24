<?php

declare(strict_types=1);

namespace Modules\Hospitality\Services;

use App\Exceptions\BusinessRuleException;
use App\Services\Tenancy\TenantClock;
use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Hospitality\Models\Accommodation;
use Modules\Hospitality\Models\FolioEntry;
use Modules\Hospitality\Models\GroupBlock;
use Modules\Hospitality\Models\GroupBlockRoom;
use Modules\Hospitality\Models\HousekeepingTask;
use Modules\Hospitality\Models\RatePlan;
use Modules\Hospitality\Models\RoomType;
use Modules\Hospitality\Models\Stay;

/**
 * Lodging: availability by room type, seasonal pricing, bookings, check-in with room assignment, the guest folio,
 * check-out (only with a settled folio) and housekeeping. Nights run from check-in (inclusive) to check-out (exclusive).
 */
final class LodgingService
{
    /**
     * Rooms of a type still free on every night of the interval, i.e. the minimum over the nights. Rooms held for the
     * open group blocks and not picked up yet are not free, except for the block named in $forBlockId.
     *
     * @return array{total: int, available: int, nights: list<array{date: string, booked: int, held: int, available: int}>}
     */
    public function availability(RoomType $type, string $from, string $to, ?string $ignoreStayId = null, ?string $forBlockId = null): array
    {
        $total = Accommodation::query()->where('room_type_id', $type->id)->where('status', 'available')->count();
        $stays = Stay::query()
            ->where('room_type_id', $type->id)
            ->whereIn('status', Stay::Holding)
            ->where('check_in_date', '<', $to)
            ->where('check_out_date', '>', $from)
            ->when($ignoreStayId !== null, static fn ($query) => $query->whereKeyNot($ignoreStayId))
            ->get(['check_in_date', 'check_out_date']);

        $blocks = $this->activeBlocks($type, $from, $to, $forBlockId);

        $nights = [];
        foreach ($this->nightsOf($from, $to) as $night) {
            $booked = $stays->filter(static fn (Stay $stay): bool => $stay->check_in_date->toDateString() <= $night && $stay->check_out_date->toDateString() > $night)->count();
            $held = (int) array_sum(array_map(
                static fn (array $block): int => $block['from'] <= $night && $block['to'] > $night ? $block['unpicked'] : 0,
                $blocks,
            ));
            $nights[] = ['date' => $night, 'booked' => $booked, 'held' => $held, 'available' => max(0, $total - $booked - $held)];
        }

        return ['total' => $total, 'available' => $nights === [] ? 0 : min(array_column($nights, 'available')), 'nights' => $nights];
    }

    /**
     * Nightly rates of the interval: the given plan (it must cover every night), else for each night the active plan of
     * the type (or of every type) with the highest priority, else the base rate of the room type.
     *
     * @return array{rates: list<string>, total: string, rate_plan_id: string|null}
     */
    public function quote(RoomType $type, string $from, string $to, ?string $ratePlanId = null): array
    {
        $nights = $this->nightsOf($from, $to);
        $plans = RatePlan::query()->where('is_active', true)->where(static fn ($query) => $query->whereNull('room_type_id')->orWhere('room_type_id', $type->id))->orderByDesc('priority')->get();

        if ($ratePlanId !== null) {
            $plan = $plans->firstWhere('id', $ratePlanId) ?? throw new BusinessRuleException('The rate plan is not active or does not apply to this room type.');
            if (count($nights) < $plan->min_stay) {
                throw new BusinessRuleException("The rate plan needs a minimum stay of {$plan->min_stay} nights.");
            }
            foreach ($nights as $night) {
                if (! $this->covers($plan, $night)) {
                    throw new BusinessRuleException('The rate plan does not cover every night of the stay.');
                }
            }
            $rates = array_fill(0, count($nights), (string) $plan->nightly_rate);
        } else {
            $rates = array_map(function (string $night) use ($plans, $type, $nights): string {
                $plan = $plans->first(fn (RatePlan $candidate): bool => $this->covers($candidate, $night) && count($nights) >= $candidate->min_stay);

                return (string) ($plan->nightly_rate ?? $type->base_rate);
            }, $nights);
        }

        $cents = array_sum(array_map(static fn (string $rate): int => (int) round(((float) $rate) * 100), $rates));

        return ['rates' => $rates, 'total' => number_format($cents / 100, 2, '.', ''), 'rate_plan_id' => $ratePlanId];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function book(array $data): Stay
    {
        $type = RoomType::query()->findOrFail($data['room_type_id']);
        $from = (string) $data['check_in_date'];
        $to = (string) $data['check_out_date'];
        if (! $type->is_active) {
            throw new BusinessRuleException('The room type is not bookable.');
        }
        if ((int) $data['adults'] > $type->max_occupancy) {
            throw new BusinessRuleException("The room type sleeps at most {$type->max_occupancy} guests.");
        }

        return DB::transaction(function () use ($data, $type, $from, $to): Stay {
            TransactionLock::acquire('hosp_stays:'.$type->tenant_id);
            $blockId = $data['group_block_id'] ?? null;
            $blockRoom = $blockId !== null ? $this->assertBlockPickup((string) $blockId, $type, $from, $to) : null;
            if ($this->availability($type, $from, $to, null, $blockId)['available'] < 1) {
                throw new BusinessRuleException('No room of this type is available for the requested nights.');
            }
            $quote = $this->quote($type, $from, $to, $data['rate_plan_id'] ?? null);
            if ($blockRoom?->nightly_rate !== null) {
                $quote['rates'] = array_fill(0, count($quote['rates']), (string) $blockRoom->nightly_rate);
                $quote['total'] = number_format(array_sum(array_map(static fn (string $rate): int => (int) round(((float) $rate) * 100), $quote['rates'])) / 100, 2, '.', '');
            }

            return Stay::create([
                'group_block_id' => $blockId,
                'number' => $this->nextNumber((string) $type->tenant_id),
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'] ?? null,
                'room_type_id' => $type->id,
                'rate_plan_id' => $quote['rate_plan_id'],
                'check_in_date' => $from,
                'check_out_date' => $to,
                'adults' => $data['adults'],
                'status' => 'booked',
                'room_total' => $quote['total'],
                'nightly_rates' => $quote['rates'],
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    /**
     * Assigns a room (the given one, else the first free, clean one of the booked type) and posts the room charges
     * and, when a rate per person and night is given, the city tax.
     *
     * @param  array<string, mixed>  $data
     */
    public function checkIn(Stay $stay, array $data): Stay
    {
        $this->assertTransition($stay, 'checked_in');
        if ($stay->check_in_date->toDateString() > now()->toDateString()) {
            throw new BusinessRuleException('The stay starts on '.$stay->check_in_date->toDateString().': check-in is not possible earlier.');
        }

        return DB::transaction(function () use ($stay, $data): Stay {
            $room = $this->pickRoom($stay, $data['accommodation_id'] ?? null);
            $stay->update(['accommodation_id' => $room->id, 'status' => 'checked_in', 'checked_in_at' => now()]);

            $nights = $this->nightsOf($stay->check_in_date->toDateString(), $stay->check_out_date->toDateString());
            foreach ($nights as $index => $night) {
                $this->post($stay, 'charge', 'room', "Room {$room->number} - {$night}", '1', (string) $stay->nightly_rates[$index], $night);
            }
            $cityTax = (float) ($data['city_tax_per_person_night'] ?? 0);
            if ($cityTax > 0) {
                $this->post($stay, 'charge', 'city_tax', 'City tax', (string) ($stay->adults * count($nights)), number_format($cityTax, 2, '.', ''), now()->toDateString());
            }

            return $stay->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function postCharge(Stay $stay, array $data): FolioEntry
    {
        $this->assertFolioOpen($stay);

        return $this->post($stay, 'charge', (string) $data['category'], (string) $data['description'], (string) ($data['quantity'] ?? 1), (string) $data['unit_amount'], (string) ($data['posted_on'] ?? now()->toDateString()));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function postPayment(Stay $stay, array $data): FolioEntry
    {
        $this->assertFolioOpen($stay);

        return $this->post($stay, 'payment', 'payment', (string) ($data['description'] ?? 'Payment'), '1', (string) $data['amount'], now()->toDateString(), (string) $data['payment_method']);
    }

    public function voidEntry(FolioEntry $entry): FolioEntry
    {
        $stay = Stay::query()->findOrFail($entry->stay_id);
        $this->assertFolioOpen($stay);
        if ($entry->voided_at !== null) {
            throw new BusinessRuleException('The folio line is already voided.');
        }
        $entry->update(['voided_at' => now()]);

        return $entry->refresh();
    }

    /**
     * Closes the stay. The folio must be settled (nothing owed); the room becomes dirty and a clean-up task is created.
     */
    public function checkOut(Stay $stay): Stay
    {
        $this->assertTransition($stay, 'checked_out');
        if ((float) $stay->folio_balance > 0) {
            throw new BusinessRuleException('Settle the folio before check-out: '.$stay->folio_balance.' is still owed.');
        }

        return DB::transaction(function () use ($stay): Stay {
            $stay->update(['status' => 'checked_out', 'checked_out_at' => now()]);
            if ($stay->accommodation_id !== null) {
                Accommodation::query()->whereKey($stay->accommodation_id)->update(['housekeeping_status' => 'dirty']);
                HousekeepingTask::create(['accommodation_id' => $stay->accommodation_id, 'task_type' => 'checkout_clean', 'status' => 'pending', 'due_on' => now()->toDateString()]);
            }

            return $stay->refresh();
        });
    }

    public function cancel(Stay $stay, bool $noShow = false): Stay
    {
        $target = $noShow ? 'no_show' : 'cancelled';
        $this->assertTransition($stay, $target);
        $stay->update(['status' => $target]);

        return $stay->refresh();
    }

    public function startTask(HousekeepingTask $task, ?string $assignedTo): HousekeepingTask
    {
        if ($task->status !== 'pending') {
            throw new BusinessRuleException("The task is {$task->status}: only a pending task can be started.");
        }
        $task->update(['status' => 'in_progress', 'started_at' => now(), 'assigned_to' => $assignedTo ?? $task->assigned_to]);

        return $task->refresh();
    }

    public function completeTask(HousekeepingTask $task): HousekeepingTask
    {
        if ($task->status === 'done') {
            throw new BusinessRuleException('The task is already done.');
        }

        return DB::transaction(function () use ($task): HousekeepingTask {
            $task->update(['status' => 'done', 'completed_at' => now(), 'started_at' => $task->started_at ?? now()]);
            Accommodation::query()->whereKey($task->accommodation_id)->update(['housekeeping_status' => $task->task_type === 'inspection' ? 'inspected' : 'clean']);

            return $task->refresh();
        });
    }

    /**
     * Availability of every active room type night by night, for the calendar grid.
     *
     * @return list<array{room_type_id: string, code: string, name: string, total: int, nights: list<array{date: string, booked: int, held: int, available: int}>}>
     */
    public function availabilityGrid(string $from, string $to): array
    {
        return RoomType::query()->where('is_active', true)->orderBy('code')->get()->map(function (RoomType $type) use ($from, $to): array {
            $availability = $this->availability($type, $from, $to);

            return ['room_type_id' => (string) $type->id, 'code' => (string) $type->code, 'name' => (string) $type->name, 'total' => $availability['total'], 'nights' => $availability['nights']];
        })->values()->all();
    }

    /**
     * The open, unexpired group blocks holding rooms of the type over the interval, with the rooms not picked up yet.
     * The block named in $exceptBlockId does not hold rooms against its own pickup.
     *
     * @return list<array{from: string, to: string, unpicked: int}>
     */
    private function activeBlocks(RoomType $type, string $from, string $to, ?string $exceptBlockId): array
    {
        $today = app(TenantClock::class)->today()->toDateString();
        $rooms = GroupBlockRoom::query()
            ->where('room_type_id', $type->id)
            ->whereHas('block', static fn ($query) => $query->where('status', 'open')->where('release_date', '>=', $today)->where('check_in_date', '<', $to)->where('check_out_date', '>', $from))
            ->when($exceptBlockId !== null, static fn ($query) => $query->where('group_block_id', '!=', $exceptBlockId))
            ->with('block')
            ->get();

        if ($rooms->isEmpty()) {
            return [];
        }

        $picked = Stay::query()
            ->whereIn('group_block_id', $rooms->pluck('group_block_id')->all())
            ->where('room_type_id', $type->id)
            ->whereIn('status', Stay::Holding)
            ->selectRaw('group_block_id, count(*) as picked')
            ->groupBy('group_block_id')
            ->pluck('picked', 'group_block_id');

        return $rooms->map(static fn (GroupBlockRoom $room): array => [
            'from' => $room->block->check_in_date->toDateString(),
            'to' => $room->block->check_out_date->toDateString(),
            'unpicked' => max(0, (int) $room->rooms_blocked - (int) ($picked[$room->group_block_id] ?? 0)),
        ])->values()->all();
    }

    /**
     * A stay is picked up from a block only while the block is open and not past its release date, inside the dates of
     * the block, for a room type of the block with rooms left to pick up.
     */
    private function assertBlockPickup(string $blockId, RoomType $type, string $from, string $to): GroupBlockRoom
    {
        $block = GroupBlock::query()->findOrFail($blockId);

        if ($block->status !== 'open' || $block->release_date->toDateString() < app(TenantClock::class)->today()->toDateString()) {
            throw new BusinessRuleException('The group block is no longer open for pickup.');
        }

        if ($from < $block->check_in_date->toDateString() || $to > $block->check_out_date->toDateString()) {
            throw new BusinessRuleException('The stay must fall inside the dates of the group block.');
        }

        $room = GroupBlockRoom::query()->where('group_block_id', $block->id)->where('room_type_id', $type->id)->first()
            ?? throw new BusinessRuleException('The group block holds no rooms of this room type.');

        $picked = Stay::query()->where('group_block_id', $block->id)->where('room_type_id', $type->id)->whereIn('status', Stay::Holding)->count();

        if ($picked >= (int) $room->rooms_blocked) {
            throw new BusinessRuleException('Every room blocked for this room type has already been picked up.');
        }

        return $room;
    }

    private function pickRoom(Stay $stay, ?string $accommodationId): Accommodation
    {
        $busy = Stay::query()->where('status', 'checked_in')->whereNotNull('accommodation_id')->whereKeyNot($stay->id)->pluck('accommodation_id')->all();
        $rooms = Accommodation::query()->where('room_type_id', $stay->room_type_id)->where('status', 'available')->whereIn('housekeeping_status', ['clean', 'inspected'])->whereNotIn('id', $busy)->orderBy('number');

        if ($accommodationId !== null) {
            return $rooms->whereKey($accommodationId)->first() ?? throw new BusinessRuleException('The room is not free, not clean, out of service or of another room type.');
        }

        return $rooms->first() ?? throw new BusinessRuleException('No clean room of the booked type is free for check-in.');
    }

    private function post(Stay $stay, string $type, string $category, string $description, string $quantity, string $unitAmount, string $postedOn, ?string $method = null): FolioEntry
    {
        $amount = number_format(round((float) $quantity * (float) $unitAmount, 2), 2, '.', '');

        return FolioEntry::create([
            'stay_id' => $stay->id,
            'entry_type' => $type,
            'category' => $category,
            'description' => $description,
            'quantity' => $quantity,
            'unit_amount' => $unitAmount,
            'amount' => $amount,
            'payment_method' => $method,
            'posted_on' => $postedOn,
        ]);
    }

    private function assertTransition(Stay $stay, string $target): void
    {
        if (! in_array($target, Stay::Transitions[$stay->status] ?? [], true)) {
            throw new BusinessRuleException("The stay is {$stay->status}: it cannot become {$target}.");
        }
    }

    private function assertFolioOpen(Stay $stay): void
    {
        if (! in_array($stay->status, Stay::Holding, true)) {
            throw new BusinessRuleException("The stay is {$stay->status}: its folio is closed.");
        }
    }

    private function covers(RatePlan $plan, string $night): bool
    {
        return $plan->valid_from->toDateString() <= $night && ($plan->valid_to === null || $plan->valid_to->toDateString() >= $night);
    }

    /**
     * @return list<string>
     */
    private function nightsOf(string $from, string $to): array
    {
        $nights = [];
        for ($day = CarbonImmutable::parse($from); $day->toDateString() < $to; $day = $day->addDay()) {
            $nights[] = $day->toDateString();
        }

        return $nights;
    }

    private function nextNumber(string $tenantId): string
    {
        $year = now()->format('Y');
        $last = DB::table('hosp_stays')->where('tenant_id', $tenantId)->where('number', 'like', "STY-{$year}-%")->max('number');

        return sprintf('STY-%s-%04d', $year, $last === null ? 1 : ((int) substr((string) $last, -4)) + 1);
    }
}
