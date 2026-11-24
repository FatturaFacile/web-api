<?php

declare(strict_types=1);

namespace Modules\Workshop\Services;

use App\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Workshop\Models\WorkshopBay;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopJobSlot;

/**
 * Technician and bay scheduling of the workshop. A technician and a bay each hold one slot at a time: a planned or
 * in-progress slot that overlaps the window is a conflict. Slots can only be planned on a folder that is open or in
 * progress.
 */
final class SchedulingService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function plan(array $data): WorkshopJobSlot
    {
        $folder = WorkshopFolder::query()->findOrFail($data['folder_id']);
        $this->assertFolderSchedulable($folder);

        return DB::transaction(function () use ($data): WorkshopJobSlot {
            $this->assertNoConflict($data['technician_user_id'] ?? null, $data['bay_id'] ?? null, (string) $data['starts_at'], (string) $data['ends_at'], null);

            return WorkshopJobSlot::create([...$this->fields($data), 'folder_id' => $data['folder_id'], 'status' => 'planned']);
        });
    }

    /**
     * Moves a planned slot: another window, technician or bay.
     *
     * @param  array<string, mixed>  $data
     */
    public function reschedule(WorkshopJobSlot $slot, array $data): WorkshopJobSlot
    {
        if ($slot->status !== 'planned') {
            throw new BusinessRuleException("The slot is {$slot->status}: only a planned slot can be moved.");
        }
        $merged = [...$slot->only(['technician_user_id', 'bay_id']), ...array_intersect_key($data, array_flip(['technician_user_id', 'bay_id']))];
        $starts = (string) ($data['starts_at'] ?? $slot->starts_at->toIso8601String());
        $ends = (string) ($data['ends_at'] ?? $slot->ends_at->toIso8601String());
        if (strtotime($ends) <= strtotime($starts)) {
            throw new BusinessRuleException('The slot must end after it starts.');
        }

        return DB::transaction(function () use ($slot, $data, $merged, $starts, $ends): WorkshopJobSlot {
            $this->assertNoConflict($merged['technician_user_id'], $merged['bay_id'], $starts, $ends, $slot->id);
            $slot->update($this->fields($data));

            return $slot->refresh();
        });
    }

    public function transition(WorkshopJobSlot $slot, string $target): WorkshopJobSlot
    {
        if (! in_array($target, WorkshopJobSlot::Transitions[$slot->status] ?? [], true)) {
            throw new BusinessRuleException("The slot is {$slot->status}: it cannot become {$target}.");
        }
        $slot->update(['status' => $target]);

        return $slot->refresh();
    }

    private function assertFolderSchedulable(WorkshopFolder $folder): void
    {
        if (! in_array($folder->status, ['open', 'in_progress'], true)) {
            throw new BusinessRuleException("The folder is {$folder->status}: work can be planned only on an open or in-progress folder.");
        }
    }

    private function assertNoConflict(?string $technicianId, ?string $bayId, string $starts, string $ends, ?string $ignoreId): void
    {
        if (strtotime($ends) <= strtotime($starts)) {
            throw new BusinessRuleException('The slot must end after it starts.');
        }
        if ($bayId !== null && ! WorkshopBay::query()->whereKey($bayId)->where('is_active', true)->exists()) {
            throw new BusinessRuleException('The bay is not active.');
        }

        $overlapping = fn () => WorkshopJobSlot::query()
            ->whereIn('status', WorkshopJobSlot::Holding)
            ->where('starts_at', '<', $this->utc($ends))
            ->where('ends_at', '>', $this->utc($starts))
            ->when($ignoreId !== null, static fn ($query) => $query->whereKeyNot($ignoreId));

        if ($technicianId !== null && $overlapping()->where('technician_user_id', $technicianId)->exists()) {
            throw new BusinessRuleException('The technician already has a slot in this window.');
        }
        if ($bayId !== null && $overlapping()->where('bay_id', $bayId)->exists()) {
            throw new BusinessRuleException('The bay is already taken in this window.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function fields(array $data): array
    {
        $fields = array_intersect_key($data, array_flip(['technician_user_id', 'bay_id', 'starts_at', 'ends_at', 'notes']));
        foreach (['starts_at', 'ends_at'] as $key) {
            if (isset($fields[$key])) {
                $fields[$key] = $this->utc((string) $fields[$key]);
            }
        }

        return $fields;
    }

    /**
     * Instants are stored and compared in UTC.
     */
    private function utc(string $instant): string
    {
        return CarbonImmutable::parse($instant)->utc()->format('Y-m-d H:i:s');
    }
}
