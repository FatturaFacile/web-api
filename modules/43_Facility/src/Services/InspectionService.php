<?php

declare(strict_types=1);

namespace Modules\Facility\Services;

use App\Services\Tenancy\TenantClock;
use App\Support\Database\TransactionLock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Facility\Models\FacilityInspection;
use Modules\Facility\Models\FacilityInspectionTemplate;

/**
 * Recurring inspections and checklists. A template holds the checklist, the frequency and the next due date; the
 * scheduler generates one inspection per due date (once only, whatever the number of runs), copying the checklist. A
 * completed inspection with a failed item raises a service request.
 *
 * Monthly, quarterly and yearly dates advance without overflow (31 January + 1 month = 28 February), so a template
 * anchored on the 31st settles on the last day of shorter months.
 */
final readonly class InspectionService
{
    /** Upper bound of the lead days of a template, also used to narrow the templates the scheduler looks at. */
    public const int MaxLeadDays = 60;

    public function __construct(
        private TenantClock $clock,
        private FacilityNumbers $numbers,
        private FacilityLedger $ledger,
        private ServiceRequestService $requests,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createTemplate(array $data): FacilityInspectionTemplate
    {
        $data['checklist'] = $this->normalizeChecklist($data['checklist']);

        return FacilityInspectionTemplate::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTemplate(FacilityInspectionTemplate $template, array $data): FacilityInspectionTemplate
    {
        if (isset($data['checklist'])) {
            $data['checklist'] = $this->normalizeChecklist($data['checklist']);
        }

        $template->update($data);

        return $template->refresh();
    }

    /**
     * Generates the inspections that are due: every template whose next due date, less its lead days, has been reached.
     *
     * @return Collection<int, FacilityInspection>
     */
    public function generateDue(?string $asOf = null, ?string $templateId = null): Collection
    {
        $today = CarbonImmutable::parse($asOf ?? $this->clock->businessDate(now()))->startOfDay();
        $created = collect();

        $templates = FacilityInspectionTemplate::query()
            ->where('is_active', true)
            ->when($templateId !== null, static fn ($query) => $query->where('id', $templateId))
            ->whereDate('next_due_date', '<=', $today->addDays(self::MaxLeadDays)->toDateString())
            ->get();

        foreach ($templates as $template) {
            foreach ($this->generateForTemplate($template, $today) as $inspection) {
                $created->push($inspection);
            }
        }

        return $created;
    }

    public function start(FacilityInspection $inspection): FacilityInspection
    {
        return DB::transaction(function () use ($inspection): FacilityInspection {
            $locked = $this->lock($inspection);

            if ($locked->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => [__('facility_errors.inspection.start_only_scheduled', ['status' => $locked->status])]]);
            }

            $locked->update(['status' => 'in_progress', 'started_at' => now()]);

            return $locked->refresh();
        });
    }

    /**
     * Records the answers of the checklist and completes the inspection. A failed pass/fail item makes the result "fail"
     * and raises a high priority service request for the location.
     *
     * @param  list<array{key: string, result?: string|null, value?: string|int|float|null, notes?: string|null}>  $answers
     */
    public function complete(FacilityInspection $inspection, array $answers, ?string $notes, ?string $actorId, string $tenantId): FacilityInspection
    {
        return DB::transaction(function () use ($inspection, $answers, $notes, $actorId, $tenantId): FacilityInspection {
            $locked = $this->lock($inspection);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.inspection.locked', ['status' => $locked->status])]]);
            }

            $byKey = collect($answers)->keyBy('key');
            $known = collect($locked->checklist)->pluck('key');
            $unknown = $byKey->keys()->diff($known);

            if ($unknown->isNotEmpty()) {
                throw ValidationException::withMessages(['answers' => [__('facility_errors.inspection.unknown_item', ['keys' => $unknown->implode(', ')])]]);
            }

            $failed = [];
            $missing = [];
            $checklist = [];

            foreach ($locked->checklist as $item) {
                $answer = $byKey->get($item['key'], []);
                $item['result'] = $answer['result'] ?? null;
                $item['value'] = $answer['value'] ?? null;
                $item['notes'] = $answer['notes'] ?? null;

                $answered = $item['type'] === 'pass_fail'
                    ? in_array($item['result'], ['pass', 'fail', 'na'], true)
                    : ($item['result'] === 'na' || ($item['value'] !== null && $item['value'] !== ''));

                if ($item['required'] && ! $answered) {
                    $missing[] = $item['label'];
                }

                if ($item['type'] === 'number' && $item['value'] !== null && $item['value'] !== '' && ! is_numeric($item['value'])) {
                    throw ValidationException::withMessages(['answers' => [__('facility_errors.inspection.not_numeric', ['item' => $item['label']])]]);
                }

                if ($item['type'] === 'pass_fail' && $item['result'] === 'fail') {
                    $failed[] = $item['label'];
                }

                $checklist[] = $item;
            }

            if ($missing !== []) {
                throw ValidationException::withMessages(['answers' => [__('facility_errors.inspection.required_missing', ['items' => implode(', ', $missing)])]]);
            }

            $locked->fill([
                'status' => 'completed',
                'result' => $failed === [] ? 'pass' : 'fail',
                'checklist' => $checklist,
                'started_at' => $locked->started_at ?? now(),
                'completed_at' => now(),
                'completed_by' => $actorId,
                'notes' => $notes,
            ])->save();

            if ($failed !== []) {
                $request = $this->requests->create($tenantId, [
                    'category' => 'repair',
                    'priority' => 'high',
                    'title' => Str::limit($locked->number.': '.implode(', ', $failed), 190, ''),
                    'description' => $notes,
                    'building_id' => $locked->building_id,
                    'space_id' => $locked->space_id,
                    'inspection_id' => $locked->id,
                ], $actorId);

                $locked->update(['service_request_id' => $request->id]);
            }

            $this->ledger->record('FACILITY_INSPECTION_COMPLETED', $locked, ['result' => $locked->result, 'failed_items' => count($failed)]);

            return $locked->refresh();
        });
    }

    public function cancel(FacilityInspection $inspection, string $reason): FacilityInspection
    {
        return DB::transaction(function () use ($inspection, $reason): FacilityInspection {
            $locked = $this->lock($inspection);

            if (! $locked->isOpen()) {
                throw ValidationException::withMessages(['status' => [__('facility_errors.inspection.locked', ['status' => $locked->status])]]);
            }

            $locked->update(['status' => 'cancelled', 'notes' => $reason]);
            $this->ledger->record('FACILITY_INSPECTION_CANCELLED', $locked);

            return $locked->refresh();
        });
    }

    /**
     * The next date of a recurrence.
     */
    public function advance(CarbonImmutable $date, string $frequency, int $interval): CarbonImmutable
    {
        $interval = max(1, $interval);

        return match ($frequency) {
            'daily' => $date->addDays($interval),
            'weekly' => $date->addWeeks($interval),
            'monthly' => $date->addMonthsNoOverflow($interval),
            'quarterly' => $date->addMonthsNoOverflow(3 * $interval),
            default => $date->addYearsNoOverflow($interval),
        };
    }

    /**
     * @return list<FacilityInspection>
     */
    private function generateForTemplate(FacilityInspectionTemplate $template, CarbonImmutable $today): array
    {
        return DB::transaction(function () use ($template, $today): array {
            TransactionLock::acquire("fac_inspection_template:{$template->id}");

            $locked = FacilityInspectionTemplate::query()->whereKey($template->id)->lockForUpdate()->firstOrFail();
            $created = [];
            $due = CarbonImmutable::instance($locked->next_due_date)->startOfDay();
            $limit = (int) config('facility.max_generated_inspections_per_run');

            while ($due->subDays($locked->lead_days)->lte($today) && count($created) < $limit) {
                $exists = FacilityInspection::query()->where('template_id', $locked->id)->whereDate('due_date', $due->toDateString())->exists();

                if (! $exists) {
                    $created[] = $this->instantiate($locked, $due);
                }

                $due = $this->advance($due, $locked->frequency, $locked->interval_count);
            }

            if ($created !== [] || $due->toDateString() !== $locked->next_due_date->toDateString()) {
                $locked->update(['next_due_date' => $due->toDateString(), 'last_generated_on' => $today->toDateString()]);
            }

            return $created;
        });
    }

    private function instantiate(FacilityInspectionTemplate $template, CarbonImmutable $due): FacilityInspection
    {
        $inspection = FacilityInspection::create([
            'template_id' => $template->id,
            'number' => $this->numbers->next('fac_inspections', 'INS', (string) $template->tenant_id),
            'title' => $template->name,
            'building_id' => $template->building_id,
            'space_id' => $template->space_id,
            'assignee_id' => $template->assignee_id,
            'due_date' => $due->toDateString(),
            'status' => 'scheduled',
            'checklist' => collect($template->checklist)->map(static fn (array $item): array => [...$item, 'result' => null, 'value' => null, 'notes' => null])->all(),
        ]);

        $this->ledger->record('FACILITY_INSPECTION_GENERATED', $inspection, ['template_id' => $template->id, 'due_date' => $due->toDateString()]);

        return $inspection;
    }

    /**
     * Gives every item a unique key and fills the defaults.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{key: string, label: string, type: string, required: bool}>
     */
    private function normalizeChecklist(array $items): array
    {
        $used = [];
        $normalized = [];

        foreach ($items as $index => $item) {
            $base = Str::slug((string) ($item['key'] ?? $item['label']), '_') ?: 'item_'.($index + 1);
            $key = $base;

            for ($suffix = 2; in_array($key, $used, true); $suffix++) {
                $key = $base.'_'.$suffix;
            }

            $used[] = $key;
            $normalized[] = [
                'key' => $key,
                'label' => (string) $item['label'],
                'type' => (string) ($item['type'] ?? 'pass_fail'),
                'required' => (bool) ($item['required'] ?? true),
            ];
        }

        return $normalized;
    }

    private function lock(FacilityInspection $inspection): FacilityInspection
    {
        return FacilityInspection::query()->whereKey($inspection->id)->lockForUpdate()->firstOrFail();
    }
}
