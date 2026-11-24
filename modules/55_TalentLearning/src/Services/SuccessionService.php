<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Services;

use Illuminate\Support\Facades\DB;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Models\SuccessionCandidate;
use Modules\TalentLearning\Models\SuccessionPosition;
use Modules\TalentLearning\Support\Rejection;

/**
 * Succession planning: critical positions and their successor candidates. A position is covered when somebody is ready
 * now and not about to leave; it is at risk when the only people ready now are likely to leave; otherwise it is
 * uncovered.
 */
final readonly class SuccessionService
{
    public const string Covered = 'covered';

    public const string AtRisk = 'at_risk';

    public const string Uncovered = 'uncovered';

    public function __construct(private HrDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPosition(string $tenantId, array $data): SuccessionPosition
    {
        $data = $this->resolvePosition($tenantId, $data, null);

        return SuccessionPosition::create([...$data, 'tenant_id' => $tenantId])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePosition(SuccessionPosition $position, array $data): SuccessionPosition
    {
        $data = $this->resolvePosition((string) $position->tenant_id, [...$position->only(['position_id', 'title', 'incumbent_employee_id']), ...$data], $position);
        $position->update($data);

        return $position->refresh();
    }

    public function deletePosition(SuccessionPosition $position): void
    {
        DB::transaction(function () use ($position): void {
            SuccessionCandidate::query()->where('succession_position_id', $position->id)->get()->each->delete();
            $position->delete();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addCandidate(SuccessionPosition $position, array $data): SuccessionCandidate
    {
        return DB::transaction(function () use ($position, $data): SuccessionCandidate {
            $tenantId = (string) $position->tenant_id;

            if ($position->status !== SuccessionPosition::Active) {
                throw Rejection::on('succession_position_id', 'succession.position_archived');
            }

            $this->assertCandidate($tenantId, $position, (string) $data['employee_id'], null);

            return SuccessionCandidate::create([
                ...$data,
                'tenant_id' => $tenantId,
                'succession_position_id' => $position->id,
                'ranking' => $data['ranking'] ?? ((int) SuccessionCandidate::query()->where('succession_position_id', $position->id)->max('ranking')) + 1,
            ])->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCandidate(SuccessionCandidate $candidate, array $data): SuccessionCandidate
    {
        $candidate->update(array_diff_key($data, ['employee_id' => true]));

        return $candidate->refresh();
    }

    /**
     * @param  iterable<SuccessionCandidate>  $candidates
     */
    public function coverage(iterable $candidates): string
    {
        $readyNow = [];

        foreach ($candidates as $candidate) {
            if ($candidate->readiness === SuccessionCandidate::ReadyNow) {
                $readyNow[] = $candidate;
            }
        }

        if ($readyNow === []) {
            return self::Uncovered;
        }

        foreach ($readyNow as $candidate) {
            if ($candidate->risk_of_loss !== 'high') {
                return self::Covered;
            }
        }

        return self::AtRisk;
    }

    /**
     * Counts of the succession plan for the dashboard.
     *
     * @return array<string, mixed>
     */
    public function summary(string $tenantId): array
    {
        $positions = SuccessionPosition::query()->with('candidates')->where('tenant_id', $tenantId)->where('status', SuccessionPosition::Active)->get();
        $coverage = [self::Covered => 0, self::AtRisk => 0, self::Uncovered => 0];
        $attention = [];
        $readiness = array_fill_keys(SuccessionCandidate::Readiness, 0);
        $highRiskCandidates = 0;

        foreach ($positions as $position) {
            $status = $this->coverage($position->candidates);
            $coverage[$status]++;

            if ($status !== self::Covered) {
                $attention[] = ['id' => $position->id, 'title' => $position->title, 'criticality' => $position->criticality, 'vacancy_risk' => $position->vacancy_risk, 'coverage' => $status];
            }

            foreach ($position->candidates as $candidate) {
                $readiness[$candidate->readiness] = ($readiness[$candidate->readiness] ?? 0) + 1;
                $highRiskCandidates += $candidate->risk_of_loss === 'high' ? 1 : 0;
            }
        }

        return [
            'positions_total' => $positions->count(),
            'coverage' => $coverage,
            'candidates_by_readiness' => $readiness,
            'high_risk_of_loss_candidates' => $highRiskCandidates,
            'positions_needing_attention' => array_slice($attention, 0, 10),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolvePosition(string $tenantId, array $data, ?SuccessionPosition $current): array
    {
        $positionId = $data['position_id'] ?? null;

        if ($positionId !== null) {
            $hrPosition = $this->directory->position($tenantId, (string) $positionId);

            if ($hrPosition === null) {
                throw Rejection::on('position_id', 'succession.position_missing');
            }

            $taken = SuccessionPosition::query()->where('tenant_id', $tenantId)->where('position_id', $positionId)->where('status', SuccessionPosition::Active)
                ->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))->exists();

            if ($taken) {
                throw Rejection::on('position_id', 'succession.position_taken');
            }

            $data['title'] = $data['title'] ?? $hrPosition->title;
        }

        if (blank($data['title'] ?? null)) {
            throw Rejection::on('title', 'succession.title_required');
        }

        $incumbent = $data['incumbent_employee_id'] ?? null;

        if ($incumbent !== null && $this->directory->employee($tenantId, (string) $incumbent) === null) {
            throw Rejection::on('incumbent_employee_id', 'employee_missing');
        }

        return array_intersect_key($data, array_flip(['position_id', 'title', 'incumbent_employee_id', 'criticality', 'vacancy_risk', 'status', 'notes']));
    }

    private function assertCandidate(string $tenantId, SuccessionPosition $position, string $employeeId, ?SuccessionCandidate $current): void
    {
        $employee = $this->directory->employee($tenantId, $employeeId);

        if ($employee === null || ! $employee->isActive) {
            throw Rejection::on('employee_id', 'employee_missing');
        }

        if ($position->incumbent_employee_id !== null && (string) $position->incumbent_employee_id === $employeeId) {
            throw Rejection::on('employee_id', 'succession.candidate_is_incumbent');
        }

        $exists = SuccessionCandidate::query()->where('succession_position_id', $position->id)->where('employee_id', $employeeId)
            ->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))->exists();

        if ($exists) {
            throw Rejection::on('employee_id', 'succession.candidate_exists');
        }
    }
}
