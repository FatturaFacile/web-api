<?php

declare(strict_types=1);

namespace Modules\AdventureParks\Services;

use Carbon\CarbonImmutable;
use Modules\AdventureParks\Domain\Enums\InspectionOutcome;
use Modules\AdventureParks\Domain\Enums\InspectionType;
use Modules\AdventureParks\Domain\Enums\PaymentStatus;
use Modules\AdventureParks\Domain\Enums\PpeCategory;
use Modules\AdventureParks\Domain\Enums\PpeSize;
use Modules\AdventureParks\Domain\Enums\PpeStatus;
use Modules\AdventureParks\Domain\Enums\SchoolBookingStatus;
use Modules\AdventureParks\Domain\Enums\TicketStatus;
use Modules\AdventureParks\Domain\Services\SizeRecommender;
use Modules\AdventureParks\Http\Resources\DailyCheckResource;
use Modules\AdventureParks\Http\Resources\WorkshopResource;
use Modules\AdventureParks\Models\PpeAsset;
use Modules\AdventureParks\Models\Ticket;

/**
 * Read models of the park: the lookups the screens need (no labels: the clients translate the keys) and the dashboard of a day.
 */
final class ParkOverviewService
{
    public function __construct(
        private readonly ParkSettings $settings,
        private readonly WorkshopCatalogue $workshops,
        private readonly SlotAvailabilityService $availability,
        private readonly PpeRegistryService $registry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lookups(): array
    {
        return [
            'sizes' => array_map(
                static fn (array $row): array => ['value' => $row['size']] + array_diff_key($row, ['size' => true]),
                SizeRecommender::fromConfig()->table(),
            ),
            'ppe_categories' => PpeCategory::values(),
            'ppe_statuses' => PpeStatus::values(),
            'ppe_sizes' => PpeSize::values(),
            'inspection_types' => InspectionType::values(),
            'inspection_outcomes' => InspectionOutcome::values(),
            'ticket_statuses' => TicketStatus::values(),
            'payment_statuses' => PaymentStatus::values(),
            'school_statuses' => SchoolBookingStatus::values(),
            'wristband_colors' => $this->settings->wristbandPalette(),
            'config' => [
                'buffer_minutes' => $this->settings->bufferMinutes(),
                'teacher_free_ratio' => $this->settings->teacherFreeRatio(),
                'teacher_free_per_disabled' => $this->settings->teacherFreePerDisabled(),
                'picnic_capacity' => $this->settings->picnicCapacity(),
            ],
            'workshops' => WorkshopResource::collection($this->workshops->all())->resolve(),
            'daily_check_items' => array_map(static fn (string $key): array => ['key' => $key], (array) config('adventure_parks.daily_check_items')),
            'annual_check_items' => array_map(static fn (string $key): array => ['key' => $key], (array) config('adventure_parks.annual_check_items')),
            'waiver' => [
                'version_id' => $this->settings->waiverVersionId(),
                'locales' => (array) config('adventure_parks.waiver_locales'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(CarbonImmutable $date): array
    {
        $alerts = $this->registry->alerts($date);
        $check = $this->registry->dailyCheckFor($date);
        $tickets = Ticket::query()->whereDate('slot_date', $date->toDateString())->get();

        return [
            'date' => $date->toDateString(),
            'slots' => $this->availability->slotsFor($date),
            'on_course_count' => (int) $tickets->where('status', TicketStatus::OnCourse->value)->sum('headcount'),
            'tickets_today' => $tickets->count(),
            'alerts' => [
                'expired_blocked' => PpeAsset::query()->where('status', PpeStatus::ExpiredBlocked->value)->count(),
                'inspection_overdue' => $alerts['counts']['inspection_overdue'],
                'expiring_soon' => $alerts['counts']['lifespan_expiring'] + $alerts['counts']['inspection_due_soon'],
                'daily_check_done' => $check !== null,
            ],
            'daily_check' => $check === null ? null : (new DailyCheckResource($check))->resolve(),
        ];
    }

    /**
     * @return array{date: string, slots: list<array<string, mixed>>}
     */
    public function availability(CarbonImmutable $date): array
    {
        return ['date' => $date->toDateString(), 'slots' => $this->availability->slotsFor($date)];
    }
}
