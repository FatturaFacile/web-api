<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Modules\AdventureParks\Exceptions\AdventureParksException;
use Modules\AdventureParks\Models\SlotTemplate;
use Modules\AdventureParks\Models\Ticket;

/**
 * Recurring slots of the park. A slot without a wristband colour gets the next colour of the palette that no other slot uses.
 */
final class SlotTemplateService
{
    public function __construct(private readonly TenantContext $tenants, private readonly ParkSettings $settings) {}

    /**
     * @return Collection<int, SlotTemplate>
     */
    public function all(): Collection
    {
        return SlotTemplate::query()->orderBy('start_time')->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?string $userId): SlotTemplate
    {
        $slot = new SlotTemplate([
            'tenant_id' => (string) $this->tenants->getTenantId(),
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'wristband_color' => $data['wristband_color'] ?? $this->nextColour(),
            'weekdays' => $this->weekdays($data['weekdays'] ?? null),
            'is_active' => (bool) ($data['is_active'] ?? true),
            'created_by' => $userId,
        ]);
        $slot->save();

        return $slot;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(SlotTemplate $slot, array $data, ?string $userId): SlotTemplate
    {
        $slot->fill(array_intersect_key($data, array_flip(['start_time', 'end_time', 'wristband_color', 'is_active'])));

        if (array_key_exists('weekdays', $data)) {
            $slot->weekdays = $this->weekdays($data['weekdays']);
        }

        if ($slot->window()->durationMinutes() <= 0) {
            throw AdventureParksException::refuse('slot_invalid', 'A slot must end after it starts.');
        }

        $slot->updated_by = $userId;
        $slot->save();

        return $slot->refresh();
    }

    public function delete(SlotTemplate $slot): void
    {
        $active = Ticket::query()->where('slot_template_id', $slot->id)->whereNotIn('status', ['RETURNED', 'NO_SHOW'])->whereDate('slot_date', '>=', now()->toDateString())->exists();

        if ($active) {
            throw AdventureParksException::refuse('slot_has_tickets', 'The slot has open bookings and cannot be deleted: deactivate it instead.', ['slot_id' => $slot->id]);
        }

        $slot->delete();
    }

    /**
     * @return list<int>|null
     */
    private function weekdays(mixed $weekdays): ?array
    {
        if (! is_array($weekdays) || $weekdays === []) {
            return null;
        }

        $days = array_values(array_unique(array_map('intval', $weekdays)));
        sort($days);

        return $days;
    }

    private function nextColour(): string
    {
        $palette = $this->settings->wristbandPalette();
        $used = SlotTemplate::query()->pluck('wristband_color')->all();

        foreach ($palette as $colour) {
            if (! in_array($colour, $used, true)) {
                return $colour;
            }
        }

        return $palette[count($used) % max(1, count($palette))] ?? 'RED';
    }
}
