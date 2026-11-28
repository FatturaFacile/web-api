<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\TicketStatus;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Models\SlotTemplate;
use Modules\AdventureParks\Models\Ticket;

/**
 * Availability engine of the timed slots. The stock of a size is the number of harnesses of that size that are in the park and valid
 * on the day (not blocked by their life or their EN 365 inspection) minus the harnesses held by the active tickets of the slots that
 * overlap [start - buffer, end + buffer] of the slot being asked about.
 */
final class SlotAvailabilityService
{
    /**
     * Sizes that can be booked (UNIVERSAL belongs to the other equipment).
     *
     * @var list<string>
     */
    public const array BOOKABLE_SIZES = ['BABY', 'JUNIOR', 'ADULT', 'XL'];

    public function __construct(private readonly ParkSettings $settings) {}

    /**
     * Slots of a day with their stock per size.
     *
     * @return list<array<string, mixed>>
     */
    public function slotsFor(CarbonInterface $date): array
    {
        $templates = SlotTemplate::query()->orderBy('start_time')->get();
        $assets = $this->harnesses();
        $tickets = $this->activeTickets($date);

        return $templates->map(fn (SlotTemplate $slot): array => $this->present($slot, $date, $assets, $tickets))->values()->all();
    }

    /**
     * @param  Collection<int, PpeAsset>|null  $assets  harnesses already loaded (and locked) by the caller
     * @return array<string, array{total: int, booked: int, remaining: int}>
     */
    public function stockFor(SlotTemplate $slot, CarbonInterface $date, ?Collection $assets = null, ?Collection $tickets = null): array
    {
        $assets ??= $this->harnesses();
        $tickets ??= $this->activeTickets($date);
        $window = $slot->window();
        $buffer = $this->settings->bufferMinutes();

        $booked = array_fill_keys(self::BOOKABLE_SIZES, 0);

        foreach ($tickets as $ticket) {
            if (! $window->overlapsWithBuffer($ticket->window(), $buffer)) {
                continue;
            }

            foreach ($ticket->lines as $line) {
                if (array_key_exists($line->size, $booked)) {
                    $booked[$line->size] += $line->quantity;
                }
            }
        }

        $stock = [];

        foreach (self::BOOKABLE_SIZES as $size) {
            $total = $assets->filter(fn (PpeAsset $asset): bool => $asset->size === $size && $asset->countsAsStockOn($date))->count();
            $stock[$size] = ['total' => $total, 'booked' => $booked[$size], 'remaining' => max(0, $total - $booked[$size])];
        }

        return $stock;
    }

    /**
     * @return Collection<int, PpeAsset>
     */
    public function harnesses(): Collection
    {
        return PpeAsset::query()->where('category', PpeCategory::Harness->value)->whereIn('size', self::BOOKABLE_SIZES)->get();
    }

    /**
     * Tickets of the day that hold harnesses.
     *
     * @return Collection<int, Ticket>
     */
    public function activeTickets(CarbonInterface $date): Collection
    {
        return Ticket::query()
            ->whereDate('slot_date', $date->toDateString())
            ->whereNotIn('status', [TicketStatus::Returned->value, TicketStatus::NoShow->value])
            ->with('lines')
            ->get();
    }

    /**
     * @param  Collection<int, PpeAsset>  $assets
     * @param  Collection<int, Ticket>  $tickets
     * @return array<string, mixed>
     */
    private function present(SlotTemplate $slot, CarbonInterface $date, Collection $assets, Collection $tickets): array
    {
        $visitors = $tickets
            ->filter(fn (Ticket $ticket): bool => $ticket->slot_template_id === $slot->id && in_array($ticket->status, [TicketStatus::Equipped->value, TicketStatus::OnCourse->value], true))
            ->sum('headcount');

        return [
            'id' => $slot->id,
            'start_time' => $slot->start_time,
            'end_time' => $slot->end_time,
            'duration_minutes' => $slot->window()->durationMinutes(),
            'wristband_color' => $slot->wristband_color,
            'is_open' => $slot->isOpenOn($date),
            'stock' => $this->stockFor($slot, $date, $assets, $tickets),
            'active_visitors' => (int) $visitors,
        ];
    }
}
