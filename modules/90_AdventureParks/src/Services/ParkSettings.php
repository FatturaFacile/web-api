<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use Modules\AdventureParks\Models\ParkConfig;

/**
 * Operating parameters of the park of the active company: its own row of adv_park_configs where it has one, the defaults of
 * config/adventure_parks.php otherwise.
 */
final class ParkSettings
{
    public function bufferMinutes(): int
    {
        return $this->row()?->buffer_minutes ?? (int) config('adventure_parks.buffer_minutes', 15);
    }

    public function teacherFreeRatio(): int
    {
        return max(1, $this->row()?->teacher_free_ratio ?? (int) config('adventure_parks.teacher_free_ratio', 15));
    }

    public function teacherFreePerDisabled(): int
    {
        return $this->row()?->teacher_free_per_disabled ?? (int) config('adventure_parks.teacher_free_per_disabled', 1);
    }

    public function picnicCapacity(): int
    {
        return $this->row()?->picnic_capacity ?? (int) config('adventure_parks.picnic_capacity', 60);
    }

    public function waiverVersionId(): string
    {
        return $this->row()?->waiver_version_id ?? (string) config('adventure_parks.waiver_version_id', 'v1');
    }

    /**
     * @return list<string>
     */
    public function wristbandPalette(): array
    {
        $palette = $this->row()?->wristband_palette;

        if (is_array($palette) && $palette !== []) {
            return array_values(array_map('strval', $palette));
        }

        /** @var list<string> $default */
        $default = (array) config('adventure_parks.wristband_palette');

        return $default;
    }

    private function row(): ?ParkConfig
    {
        return ParkConfig::query()->first();
    }
}
