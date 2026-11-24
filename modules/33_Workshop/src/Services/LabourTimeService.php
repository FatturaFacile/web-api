<?php

declare(strict_types=1);

namespace Modules\Workshop\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Workshop\Models\TimeEntry;
use Modules\Workshop\Models\WorkshopFolder;
use Modules\Workshop\Models\WorkshopFolderLine;

/**
 * Labour times of a workshop: a technician clocks on a folder in progress and off again, or the time is entered
 * afterwards; at the end the unbilled time becomes one labour line per technician at the hourly rate chosen by the user
 * (the module applies no default rate). Minutes are whole numbers; hours are decimal strings with four digits.
 */
final class LabourTimeService
{
    public function __construct(private readonly WorkshopService $workshop) {}

    /**
     * Clocks a technician on a folder that is in progress. A technician works on one folder at a time.
     */
    public function start(WorkshopFolder $folder, string $technicianId, ?string $notes): TimeEntry
    {
        return DB::transaction(function () use ($folder, $technicianId, $notes): TimeEntry {
            User::query()->whereKey($technicianId)->lockForUpdate()->firstOrFail();
            $locked = WorkshopFolder::query()->lockForUpdate()->findOrFail($folder->id);

            if ($locked->status !== 'in_progress') {
                throw new BusinessRuleException('Time is clocked only on a folder in progress.');
            }

            if (TimeEntry::query()->where('technician_user_id', $technicianId)->whereNull('ended_at')->exists()) {
                throw new BusinessRuleException('The technician is already clocked on a job: stop it first.');
            }

            $entry = new TimeEntry;
            $entry->fill(['folder_id' => $locked->id, 'technician_user_id' => $technicianId, 'started_at' => CarbonImmutable::now(), 'notes' => $notes]);
            $entry->save();

            return $entry;
        });
    }

    /**
     * Clocks the technician off: the entry keeps the whole minutes elapsed (rounded down).
     */
    public function stop(TimeEntry $entry): TimeEntry
    {
        return DB::transaction(function () use ($entry): TimeEntry {
            $locked = TimeEntry::query()->lockForUpdate()->findOrFail($entry->id);

            if ($locked->ended_at !== null) {
                throw new BusinessRuleException('The time entry is already stopped.');
            }

            $ended = CarbonImmutable::now();
            $locked->update(['ended_at' => $ended, 'minutes' => max(0, (int) floor($locked->started_at->diffInSeconds($ended, false) / 60))]);

            return $locked;
        });
    }

    /**
     * Enters time worked on a folder that is not invoiced or cancelled, for work not clocked on the job.
     *
     * @param  array{technician_user_id: string, started_at: string, minutes: int, notes?: ?string}  $data
     */
    public function enter(WorkshopFolder $folder, array $data): TimeEntry
    {
        return DB::transaction(function () use ($folder, $data): TimeEntry {
            $locked = WorkshopFolder::query()->lockForUpdate()->findOrFail($folder->id);

            if ($locked->isLocked()) {
                throw new BusinessRuleException('Only a folder that is not invoiced or cancelled takes time.');
            }

            $start = CarbonImmutable::parse($data['started_at']);
            $entry = new TimeEntry;
            $entry->fill([
                'folder_id' => $locked->id,
                'technician_user_id' => $data['technician_user_id'],
                'started_at' => $start,
                'ended_at' => $start->addMinutes((int) $data['minutes']),
                'minutes' => (int) $data['minutes'],
                'notes' => $data['notes'] ?? null,
            ]);
            $entry->save();

            return $entry;
        });
    }

    /**
     * Turns the unbilled time of a folder into labour lines, one per technician, at the hourly rate and tax rate given.
     * Running entries must be stopped first. Every entry is linked to the line that bills it, so time is billed once.
     *
     * @return Collection<int, WorkshopFolderLine>
     */
    public function bill(WorkshopFolder $folder, string $hourlyRate, string $taxRate, ?string $description): Collection
    {
        return DB::transaction(function () use ($folder, $hourlyRate, $taxRate, $description): Collection {
            $locked = WorkshopFolder::query()->lockForUpdate()->findOrFail($folder->id);
            $entries = TimeEntry::query()->where('folder_id', $locked->id)->whereNull('folder_line_id')->lockForUpdate()->get();

            if ($entries->contains(static fn (TimeEntry $entry): bool => $entry->ended_at === null)) {
                throw new BusinessRuleException('Some time is still running: stop it before billing.');
            }

            if ($entries->sum('minutes') === 0) {
                throw new BusinessRuleException('There is no unbilled time on the folder.');
            }

            return $entries->groupBy('technician_user_id')->map(function (Collection $own, string $technicianId) use ($locked, $hourlyRate, $taxRate, $description): WorkshopFolderLine {
                $line = $this->workshop->addLine($locked, [
                    'line_type' => 'labour',
                    'description' => $description ?? 'Labour',
                    'quantity' => bcdiv((string) $own->sum('minutes'), '60', 4),
                    'unit_price' => $hourlyRate,
                    'tax_rate' => $taxRate,
                    'technician_user_id' => $technicianId,
                ]);
                TimeEntry::query()->whereIn('id', $own->pluck('id'))->update(['folder_line_id' => $line->id]);

                return $line;
            })->values();
        });
    }

    /**
     * The entries of a folder with the minutes and hours per technician, billed and not.
     *
     * @return array{entries: Collection<int, TimeEntry>, technicians: list<array{technician_user_id: string, minutes: int, hours: string, unbilled_minutes: int}>}
     */
    public function summary(WorkshopFolder $folder): array
    {
        $entries = TimeEntry::query()->where('folder_id', $folder->id)->orderBy('started_at')->get();
        $technicians = $entries->groupBy('technician_user_id')->map(static function (Collection $own, string $technicianId): array {
            $minutes = (int) $own->sum('minutes');

            return [
                'technician_user_id' => $technicianId,
                'minutes' => $minutes,
                'hours' => bcdiv((string) $minutes, '60', 2),
                'unbilled_minutes' => (int) $own->whereNull('folder_line_id')->sum('minutes'),
            ];
        })->values()->all();

        return ['entries' => $entries, 'technicians' => $technicians];
    }
}
