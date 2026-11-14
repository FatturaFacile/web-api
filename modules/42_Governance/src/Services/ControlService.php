<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Governance\Models\GovControl;
use Modules\Governance\Models\GovControlTestRun;

/**
 * The controls library and the tests of the controls. An active control must be tested on a schedule that follows its
 * frequency; every test records the result and the evidence and schedules the next one (a failed control is retested
 * within a month). A failed or partial test can raise a finding in the same step.
 */
final readonly class ControlService
{
    /** Days until the retest of a control that failed its last test. */
    private const int RetestDaysAfterFailure = 30;

    public function __construct(
        private GovernanceNumbers $numbers,
        private GovernanceLedger $ledger,
        private FindingService $findings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): GovControl
    {
        return DB::transaction(function () use ($tenantId, $data): GovControl {
            $control = GovControl::create([
                'tenant_id' => $tenantId,
                'code' => $this->numbers->next('gov_controls', 'CTL', $tenantId),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'control_type' => $data['control_type'],
                'frequency' => $data['frequency'],
                'framework_reference' => $data['framework_reference'] ?? null,
                'owner_id' => $data['owner_id'] ?? null,
                'status' => 'draft',
            ]);

            if (($data['status'] ?? 'draft') !== 'draft') {
                $control = $this->changeStatus($control, (string) $data['status']);
            }

            $this->ledger->record('GOV_CONTROL_CREATED', $control, ['control_type' => $control->control_type, 'frequency' => $control->frequency]);

            return $control;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GovControl $control, array $data): GovControl
    {
        return DB::transaction(function () use ($control, $data): GovControl {
            $control->update(array_intersect_key($data, array_flip(['title', 'description', 'control_type', 'frequency', 'framework_reference', 'owner_id'])));

            if (isset($data['frequency']) && $control->status === 'active') {
                $control->update(['next_test_due_date' => $this->nextTestDate($control, $control->last_tested_at)]);
            }

            if (isset($data['status']) && $data['status'] !== $control->status) {
                $control = $this->changeStatus($control, (string) $data['status']);
            }

            return $control->refresh();
        });
    }

    /**
     * Records a test of an active control: the result, the sample and the evidence documents of the DMS.
     *
     * @param  array<string, mixed>  $data
     */
    public function recordTest(GovControl $control, array $data, ?string $actorId): GovControlTestRun
    {
        if ($control->status !== 'active') {
            throw ValidationException::withMessages(['control_id' => [__('governance_errors.control.test_needs_active', ['status' => $control->status])]]);
        }

        return DB::transaction(function () use ($control, $data, $actorId): GovControlTestRun {
            $locked = GovControl::query()->whereKey($control->id)->lockForUpdate()->firstOrFail();
            $testedOn = Carbon::parse((string) $data['tested_on'])->startOfDay();

            $run = GovControlTestRun::create([
                'tenant_id' => $locked->tenant_id,
                'control_id' => $locked->id,
                'tested_on' => $testedOn->toDateString(),
                'tester_id' => $actorId,
                'result' => $data['result'],
                'sample_size' => $data['sample_size'] ?? null,
                'exceptions_found' => $data['exceptions_found'] ?? null,
                'notes' => $data['notes'] ?? null,
                'evidence_document_ids' => array_values($data['evidence_document_ids'] ?? []),
            ]);

            if (($data['raise_finding'] ?? false) && $data['result'] !== 'pass') {
                $finding = $this->findings->create($locked->tenant_id, [
                    'source' => 'control_test',
                    'control_id' => $locked->id,
                    'title' => __('governance_errors.control.finding_title', ['code' => $locked->code, 'title' => $locked->title]),
                    'description' => $data['notes'] ?? null,
                    'severity' => $data['result'] === 'fail' ? 'high' : 'medium',
                    'owner_id' => $locked->owner_id,
                ]);
                $run->update(['finding_id' => $finding->id]);
            }

            $isLatest = $locked->last_tested_at === null || $testedOn->gte($locked->last_tested_at);

            if ($isLatest) {
                $locked->update([
                    'last_tested_at' => $testedOn->toDateString(),
                    'last_test_result' => $run->result,
                    'next_test_due_date' => $run->result === 'fail'
                        ? $testedOn->copy()->addDays(self::RetestDaysAfterFailure)->toDateString()
                        : $this->nextTestDate($locked, $testedOn),
                ]);
            }

            $this->ledger->record('GOV_CONTROL_TESTED', $locked, ['result' => $run->result, 'evidence' => count($run->evidence_document_ids ?? []), 'finding_id' => $run->finding_id]);

            return $run;
        });
    }

    /**
     * Date of the next test of a control, from the date of the last one (or today when it was never tested).
     */
    public function nextTestDate(GovControl $control, ?Carbon $from): string
    {
        return ($from ?? Carbon::today())->copy()->addMonthsNoOverflow(GovControl::TestIntervalMonths[$control->frequency])->toDateString();
    }

    private function changeStatus(GovControl $control, string $to): GovControl
    {
        StatusMachine::assertAllowed(GovControl::Transitions, $control->status, $to);

        $changes = ['status' => $to];

        if ($to === 'active' && $control->next_test_due_date === null) {
            $changes['next_test_due_date'] = $this->nextTestDate($control, $control->last_tested_at);
        }

        $from = $control->status;
        $control->update($changes);
        $this->ledger->record('GOV_CONTROL_STATUS_CHANGED', $control, ['from' => $from, 'to' => $to]);

        return $control->refresh();
    }
}
