<?php

declare(strict_types=1);

namespace Modules\PassengerTransport\Services;

use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\PassengerTransport\Domain\Enums\DddFileType;
use Modules\PassengerTransport\Domain\Support\TachographDddParser;
use Modules\PassengerTransport\Exceptions\PassengerTransportException;
use Modules\PassengerTransport\Models\DddArchive;
use Modules\PassengerTransport\Models\DriverComplianceState;
use Modules\PassengerTransport\Models\DriverTachographEvent;

/**
 * Archives an uploaded tachograph file (.DDD) with its SHA-256, reads the driving of a driver card, records the days and the
 * infringements and refreshes the rolling counters of the driver from the latest days of the file.
 */
final class TachographService
{
    public function __construct(private readonly TenantContext $tenantContext, private readonly TachographDddParser $parser = new TachographDddParser) {}

    public function upload(UploadedFile $file, ?string $driverEmployeeId, ?string $vehicleId, ?string $userId): DddArchive
    {
        $binary = (string) file_get_contents($file->getRealPath());
        $hash = hash('sha256', $binary);

        if (DddArchive::query()->where('sha256', $hash)->exists()) {
            throw new PassengerTransportException('ddd_duplicate', 409);
        }

        $parsed = $this->parser->parse($binary);
        $state = $this->resolveDriver($parsed['type'], $parsed['card_number'], $driverEmployeeId);

        return DB::transaction(function () use ($file, $binary, $hash, $parsed, $state, $vehicleId, $userId): DddArchive {
            $path = sprintf('passenger-transport/%s/ddd/%s.ddd', $this->tenantContext->getTenantId(), $hash);
            Storage::disk((string) config('passenger_transport.storage_disk'))->put($path, $binary);

            $archive = DddArchive::query()->create([
                'driver_employee_id' => $state?->driver_employee_id,
                'vehicle_id' => $vehicleId,
                'file_type' => $parsed['type']->value,
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'storage_path' => $path,
                'file_size' => strlen($binary),
                'sha256' => $hash,
                'card_number' => $parsed['card_number'],
                'period_start' => $parsed['period_start'],
                'period_end' => $parsed['period_end'],
                'infringements_count' => count($parsed['infringements']),
                'infringements_summary' => $parsed['infringements'],
                'created_by' => $userId,
            ]);

            if ($state !== null) {
                $this->recordEvents($archive, $state->driver_employee_id, $parsed);
                $this->refreshCounters($state, $parsed);
            }

            return $archive;
        });
    }

    private function resolveDriver(DddFileType $type, ?string $cardNumber, ?string $driverEmployeeId): ?DriverComplianceState
    {
        if ($type === DddFileType::VehicleUnit) {
            return null;
        }

        $state = $driverEmployeeId !== null
            ? DriverComplianceState::query()->where('driver_employee_id', $driverEmployeeId)->first()
            : DriverComplianceState::query()->where('tachograph_card_number', $cardNumber)->first();

        if ($state === null) {
            throw new PassengerTransportException('ddd_driver_unresolved', 422);
        }

        if ($cardNumber !== null && $state->tachograph_card_number !== null && $state->tachograph_card_number !== $cardNumber) {
            throw new PassengerTransportException('ddd_card_mismatch', 422, ['card' => $cardNumber]);
        }

        return $state;
    }

    /**
     * @param  array{days: list<array{date: string, driving_minutes: int, work_minutes: int, longest_rest_minutes: int, continuous_driving_minutes_at_end: int}>, infringements: list<array{code: string, date: string, minutes: int}>}  $parsed
     */
    private function recordEvents(DddArchive $archive, string $driverId, array $parsed): void
    {
        foreach ($parsed['days'] as $day) {
            DriverTachographEvent::query()->create([
                'driver_employee_id' => $driverId,
                'ddd_archive_id' => $archive->id,
                'event_type' => DriverTachographEvent::ActivityDay,
                'event_date' => $day['date'],
                'driving_minutes' => $day['driving_minutes'],
                'work_minutes' => $day['work_minutes'],
                'longest_rest_minutes' => $day['longest_rest_minutes'],
            ]);
        }

        foreach ($parsed['infringements'] as $infringement) {
            DriverTachographEvent::query()->create([
                'driver_employee_id' => $driverId,
                'ddd_archive_id' => $archive->id,
                'event_type' => DriverTachographEvent::Infringement,
                'event_date' => $infringement['date'],
                'infringement_code' => $infringement['code'],
                'infringement_minutes' => $infringement['minutes'],
            ]);
        }
    }

    /**
     * @param  array{card_expires_on: string|null, days: list<array{date: string, driving_minutes: int, work_minutes: int, longest_rest_minutes: int, continuous_driving_minutes_at_end: int}>}  $parsed
     */
    private function refreshCounters(DriverComplianceState $state, array $parsed): void
    {
        $changes = [];

        if ($parsed['card_expires_on'] !== null) {
            $changes['tachograph_card_expiry_date'] = $parsed['card_expires_on'];
        }

        if ($parsed['days'] !== []) {
            $latest = $parsed['days'][count($parsed['days']) - 1];
            $latestDate = CarbonImmutable::parse($latest['date']);
            $weekStart = $latestDate->startOfWeek();
            $weekly = 0;
            $previousWeek = 0;
            $extended = 0;

            foreach ($parsed['days'] as $day) {
                $date = CarbonImmutable::parse($day['date']);

                if ($date->greaterThanOrEqualTo($weekStart)) {
                    $weekly += $day['driving_minutes'];
                    $extended += $day['driving_minutes'] > 540 ? 1 : 0;
                } elseif ($date->greaterThanOrEqualTo($weekStart->subWeek())) {
                    $previousWeek += $day['driving_minutes'];
                }
            }

            $changes += [
                'daily_driving_minutes_used' => $latest['driving_minutes'],
                'extended_10h_days_used_this_week' => min(2, $extended),
                'continuous_driving_minutes_since_break' => $latest['continuous_driving_minutes_at_end'],
                'weekly_driving_minutes_used' => $weekly,
                'biweekly_driving_minutes_used' => $weekly + $previousWeek,
                'last_daily_rest_duration_minutes' => max(0, $latest['longest_rest_minutes']),
                'available_for_next_trip' => $latest['driving_minutes'] < 540,
                'counters_updated_at' => $latestDate->endOfDay(),
            ];
        }

        $state->update($changes);
    }
}
