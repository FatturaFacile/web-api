<?php

declare(strict_types=1);

namespace Modules\HumanResources\Services\TimeTracking;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\HumanResources\Models\TimeBadge;
use Modules\HumanResources\Models\TimeClockEvent;
use Modules\HumanResources\Models\TimeMealBooking;
use Modules\HumanResources\Models\TimePunchImport;
use Modules\HumanResources\Services\TimeRecordService;

/**
 * Takes punches from any reader in a neutral form: the reader sends its own id, a unique id per punch, a badge code or a
 * user id, the local or offset time and the kind of punch. Sending a batch twice changes nothing. Every punch goes through
 * the audited record path with its real time; a sequence that makes no sense shows up as an anomaly in the report, as for
 * any record entered after the fact.
 */
final class TimePunchImportService
{
    public const string MealPunch = 'Meal';

    public function __construct(
        private readonly TimeRecordService $records,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  list<array{external_id: string, badge_code?: string|null, employee_id?: string|null, occurred_at: string, punch_type: string, meal_type?: string|null}>  $punches
     * @return array{accepted: int, duplicates: int, rejected: list<array{external_id: string, reason: string}>}
     */
    public function import(User $actor, string $readerId, TimeRecordSource $source, array $punches): array
    {
        $accepted = 0;
        $duplicates = 0;
        $rejected = [];

        foreach ($punches as $punch) {
            if (TimePunchImport::query()->where('reader_id', $readerId)->where('external_id', $punch['external_id'])->exists()) {
                $duplicates++;

                continue;
            }

            $userId = $this->resolveUser($punch);
            $occurredAt = SwissWorkingTimeRules::parseLocal($punch['occurred_at']);

            if ($userId === null) {
                $rejected[] = ['external_id' => $punch['external_id'], 'reason' => 'Badge o dipendente sconosciuto.'];

                continue;
            }

            if ($occurredAt > CarbonImmutable::now()) {
                $rejected[] = ['external_id' => $punch['external_id'], 'reason' => 'Timbratura nel futuro.'];

                continue;
            }

            DB::transaction(fn () => $this->store($actor, $readerId, $source, $punch, $userId, $occurredAt));
            $accepted++;
        }

        return ['accepted' => $accepted, 'duplicates' => $duplicates, 'rejected' => $rejected];
    }

    /**
     * Book a meal once per user, day and type.
     */
    public function bookMeal(User $actor, string $userId, CarbonImmutable $occurredAt, string $mealType, TimeRecordSource $source, ?string $note): TimeMealBooking
    {
        return TimeMealBooking::query()->firstOrCreate(
            ['user_id' => $userId, 'meal_date' => SwissWorkingTimeRules::toLocal($occurredAt)->format('Y-m-d'), 'meal_type' => $mealType],
            ['occurred_at' => $occurredAt, 'source' => $source, 'note' => $note, 'created_by' => $actor->id],
        );
    }

    /**
     * @param  array{external_id: string, punch_type: string, meal_type?: string|null}  $punch
     */
    private function store(User $actor, string $readerId, TimeRecordSource $source, array $punch, string $userId, CarbonImmutable $occurredAt): void
    {
        $event = null;
        $meal = null;

        if ($punch['punch_type'] === self::MealPunch) {
            $meal = $this->bookMeal($actor, $userId, $occurredAt, (string) ($punch['meal_type'] ?? 'lunch'), $source, null);
        } else {
            $event = $this->records->record($actor, $userId, $occurredAt, TimeEventType::from($punch['punch_type']), $source, null, "Importazione dal lettore {$readerId}");
        }

        TimePunchImport::create([
            'reader_id' => $readerId,
            'external_id' => $punch['external_id'],
            'user_id' => $userId,
            'time_clock_event_id' => $event instanceof TimeClockEvent ? $event->id : null,
            'time_meal_booking_id' => $meal?->id,
            'imported_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array{badge_code?: string|null, employee_id?: string|null}  $punch
     */
    private function resolveUser(array $punch): ?string
    {
        $userId = $punch['employee_id'] ?? TimeBadge::query()->where('badge_code', $punch['badge_code'] ?? '')->where('is_active', true)->value('user_id');

        if ($userId === null) {
            return null;
        }

        $user = User::query()->find($userId);

        return $user !== null && $user->belongsToTenant((string) $this->tenantContext->getTenantId()) ? (string) $user->id : null;
    }
}
