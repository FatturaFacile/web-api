<?php

declare(strict_types=1);

namespace Modules\TalentLearning\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\TalentLearning\Contracts\HrDirectory;
use Modules\TalentLearning\Models\CompBudgetPool;
use Modules\TalentLearning\Models\CompCycle;
use Modules\TalentLearning\Models\CompGuideline;
use Modules\TalentLearning\Models\CompProposal;
use Modules\TalentLearning\Support\Rejection;

/**
 * Compensation planning. A cycle holds budget pools and merit guidelines per performance rating. A proposal outside the
 * guideline is an exception and needs a justification; submitted and approved proposals both draw on the budget of
 * their pool, which can never be exceeded. Amounts are computed in cents.
 */
final readonly class CompensationService
{
    public function __construct(private HrDirectory $directory) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCycle(string $tenantId, array $data): CompCycle
    {
        return CompCycle::create([...$data, 'tenant_id' => $tenantId, 'status' => CompCycle::Draft])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateCycle(CompCycle $cycle, array $data): CompCycle
    {
        if ($cycle->status === CompCycle::Closed) {
            throw Rejection::on('status', 'compensation.cycle_closed');
        }

        if ($cycle->status === CompCycle::Open && isset($data['currency']) && $data['currency'] !== $cycle->currency) {
            throw Rejection::on('currency', 'compensation.currency_locked');
        }

        $cycle->update(array_diff_key($data, ['status' => true]));

        return $cycle->refresh();
    }

    public function deleteCycle(CompCycle $cycle): void
    {
        if ($cycle->status !== CompCycle::Draft || $cycle->proposals()->exists()) {
            throw Rejection::on('status', 'compensation.cycle_not_deletable');
        }

        DB::transaction(function () use ($cycle): void {
            $cycle->pools()->get()->each->delete();
            $cycle->guidelines()->get()->each->delete();
            $cycle->delete();
        });
    }

    public function openCycle(CompCycle $cycle): CompCycle
    {
        if ($cycle->status !== CompCycle::Draft) {
            throw Rejection::on('status', 'compensation.cycle_not_draft');
        }

        if (! $cycle->pools()->exists()) {
            throw Rejection::on('status', 'compensation.no_pools');
        }

        if (! $cycle->guidelines()->exists()) {
            throw Rejection::on('status', 'compensation.no_guidelines');
        }

        $cycle->update(['status' => CompCycle::Open]);

        return $cycle->refresh();
    }

    public function closeCycle(CompCycle $cycle): CompCycle
    {
        if ($cycle->status !== CompCycle::Open) {
            throw Rejection::on('status', 'compensation.cycle_not_open');
        }

        if ($cycle->proposals()->where('status', CompProposal::Submitted)->exists()) {
            throw Rejection::on('status', 'compensation.pending_proposals');
        }

        $cycle->update(['status' => CompCycle::Closed, 'closed_at' => Carbon::now()]);

        return $cycle->refresh();
    }

    /**
     * Replaces the guidelines of the cycle.
     *
     * @param  list<array{performance_rating: int, min_percent: string|int|float, max_percent: string|int|float}>  $rows
     * @return list<CompGuideline>
     */
    public function replaceGuidelines(CompCycle $cycle, array $rows): array
    {
        if ($cycle->status === CompCycle::Closed) {
            throw Rejection::on('guidelines', 'compensation.cycle_closed');
        }

        $seen = [];

        foreach ($rows as $index => $row) {
            if (isset($seen[$row['performance_rating']])) {
                throw Rejection::on("guidelines.$index.performance_rating", 'compensation.duplicate_rating');
            }

            $seen[$row['performance_rating']] = true;

            if ((float) $row['min_percent'] > (float) $row['max_percent']) {
                throw Rejection::on("guidelines.$index.max_percent", 'compensation.guideline_range');
            }
        }

        return DB::transaction(function () use ($cycle, $rows): array {
            $cycle->guidelines()->get()->each->delete();

            return array_map(static fn (array $row): CompGuideline => CompGuideline::create([
                'tenant_id' => $cycle->tenant_id,
                'cycle_id' => $cycle->id,
                'performance_rating' => $row['performance_rating'],
                'min_percent' => $row['min_percent'],
                'max_percent' => $row['max_percent'],
            ]), $rows);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPool(CompCycle $cycle, array $data): CompBudgetPool
    {
        if ($cycle->status === CompCycle::Closed) {
            throw Rejection::on('cycle_id', 'compensation.cycle_closed');
        }

        $this->assertPoolFields((string) $cycle->tenant_id, $cycle, $data, null);

        return CompBudgetPool::create([...$data, 'tenant_id' => $cycle->tenant_id, 'cycle_id' => $cycle->id])->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePool(CompBudgetPool $pool, array $data): CompBudgetPool
    {
        $cycle = $pool->cycle()->firstOrFail();

        if ($cycle->status === CompCycle::Closed) {
            throw Rejection::on('cycle_id', 'compensation.cycle_closed');
        }

        $this->assertPoolFields((string) $pool->tenant_id, $cycle, $data, $pool);

        if (isset($data['budget_amount']) && $this->cents((string) $data['budget_amount']) < $this->committedCents($pool, null)) {
            throw Rejection::on('budget_amount', 'compensation.budget_below_committed', ['committed' => $this->format($this->committedCents($pool, null))]);
        }

        $pool->update(array_diff_key($data, ['cycle_id' => true]));

        return $pool->refresh();
    }

    public function deletePool(CompBudgetPool $pool): void
    {
        if ($pool->proposals()->exists()) {
            throw Rejection::on('id', 'compensation.pool_in_use');
        }

        $pool->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createProposal(string $tenantId, array $data): CompProposal
    {
        return DB::transaction(function () use ($tenantId, $data): CompProposal {
            $cycle = CompCycle::query()->where('tenant_id', $tenantId)->findOrFail($data['cycle_id']);

            if ($cycle->status !== CompCycle::Open) {
                throw Rejection::on('cycle_id', 'compensation.cycle_not_open');
            }

            $pool = CompBudgetPool::query()->where('tenant_id', $tenantId)->where('cycle_id', $cycle->id)->find($data['budget_pool_id']);

            if ($pool === null) {
                throw Rejection::on('budget_pool_id', 'compensation.pool_not_in_cycle');
            }

            $employeeId = (string) $data['employee_id'];
            $employee = $this->directory->employee($tenantId, $employeeId);

            if ($employee === null || ! $employee->isActive) {
                throw Rejection::on('employee_id', 'employee_missing');
            }

            if ($pool->department_id !== null && $employee->departmentId !== (string) $pool->department_id) {
                throw Rejection::on('employee_id', 'compensation.employee_outside_pool');
            }

            $exists = CompProposal::query()->where('cycle_id', $cycle->id)->where('employee_id', $employeeId)->where('status', '!=', CompProposal::Rejected)->exists();

            if ($exists) {
                throw Rejection::on('employee_id', 'compensation.proposal_exists');
            }

            $salary = $this->directory->baseAnnualSalary($tenantId, $employeeId);

            if ($salary === null || $this->cents($salary['amount']) <= 0) {
                throw Rejection::on('employee_id', 'compensation.no_salary');
            }

            if ($salary['currency'] !== $cycle->currency) {
                throw Rejection::on('employee_id', 'compensation.currency_mismatch', ['salary' => $salary['currency'], 'cycle' => $cycle->currency]);
            }

            $figures = $this->figures($cycle, $salary['amount'], (int) $data['performance_rating'], (string) $data['increase_percent'], $data['justification'] ?? null);

            return CompProposal::create([
                'tenant_id' => $tenantId,
                'cycle_id' => $cycle->id,
                'budget_pool_id' => $pool->id,
                'employee_id' => $employeeId,
                'performance_rating' => $data['performance_rating'],
                'justification' => $data['justification'] ?? null,
                'status' => CompProposal::Draft,
                ...$figures,
            ])->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateProposal(CompProposal $proposal, array $data): CompProposal
    {
        if ($proposal->status !== CompProposal::Draft) {
            throw Rejection::on('status', 'compensation.proposal_not_draft');
        }

        return DB::transaction(function () use ($proposal, $data): CompProposal {
            $cycle = $proposal->cycle()->firstOrFail();

            if ($cycle->status !== CompCycle::Open) {
                throw Rejection::on('cycle_id', 'compensation.cycle_not_open');
            }

            if (isset($data['budget_pool_id']) && $data['budget_pool_id'] !== (string) $proposal->budget_pool_id) {
                $pool = CompBudgetPool::query()->where('tenant_id', $proposal->tenant_id)->where('cycle_id', $cycle->id)->find($data['budget_pool_id']);

                if ($pool === null) {
                    throw Rejection::on('budget_pool_id', 'compensation.pool_not_in_cycle');
                }

                $proposal->budget_pool_id = $pool->id;
            }

            $rating = (int) ($data['performance_rating'] ?? $proposal->performance_rating);
            $percent = (string) ($data['increase_percent'] ?? $proposal->increase_percent);
            $justification = array_key_exists('justification', $data) ? $data['justification'] : $proposal->justification;
            $figures = $this->figures($cycle, (string) $proposal->current_salary, $rating, $percent, $justification);

            $proposal->fill(['performance_rating' => $rating, 'justification' => $justification, ...$figures])->save();

            return $proposal->refresh();
        });
    }

    public function deleteProposal(CompProposal $proposal): void
    {
        if ($proposal->status !== CompProposal::Draft) {
            throw Rejection::on('status', 'compensation.proposal_not_draft');
        }

        $proposal->delete();
    }

    public function submit(CompProposal $proposal): CompProposal
    {
        return DB::transaction(function () use ($proposal): CompProposal {
            $proposal = CompProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($proposal->status !== CompProposal::Draft) {
                throw Rejection::on('status', 'compensation.proposal_not_draft');
            }

            if ($proposal->cycle()->firstOrFail()->status !== CompCycle::Open) {
                throw Rejection::on('cycle_id', 'compensation.cycle_not_open');
            }

            $this->assertWithinBudget($proposal);
            $proposal->update(['status' => CompProposal::Submitted, 'submitted_at' => Carbon::now()]);

            return $proposal->refresh();
        });
    }

    public function approve(CompProposal $proposal, string $userId, ?string $note): CompProposal
    {
        return DB::transaction(function () use ($proposal, $userId, $note): CompProposal {
            $proposal = CompProposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            if ($proposal->status !== CompProposal::Submitted) {
                throw Rejection::on('status', 'compensation.proposal_not_submitted');
            }

            $this->assertWithinBudget($proposal);
            $proposal->update(['status' => CompProposal::Approved, 'decided_by_user_id' => $userId, 'decided_at' => Carbon::now(), 'decision_note' => $note]);

            return $proposal->refresh();
        });
    }

    public function reject(CompProposal $proposal, string $userId, string $note): CompProposal
    {
        if ($proposal->status !== CompProposal::Submitted) {
            throw Rejection::on('status', 'compensation.proposal_not_submitted');
        }

        $proposal->update(['status' => CompProposal::Rejected, 'decided_by_user_id' => $userId, 'decided_at' => Carbon::now(), 'decision_note' => $note]);

        return $proposal->refresh();
    }

    /**
     * Budget, commitments and results of a cycle.
     *
     * @return array<string, mixed>
     */
    public function summary(CompCycle $cycle): array
    {
        $pools = $cycle->pools()->get();
        $proposals = $cycle->proposals()->get();
        $rows = [];
        $totalBudget = $totalCommitted = $totalApproved = 0;

        foreach ($pools as $pool) {
            $own = $proposals->where('budget_pool_id', $pool->id);
            $budget = $this->cents((string) $pool->budget_amount);
            $committed = $this->sumCents($own->whereIn('status', [CompProposal::Submitted, CompProposal::Approved]));
            $approved = $this->sumCents($own->where('status', CompProposal::Approved));
            $totalBudget += $budget;
            $totalCommitted += $committed;
            $totalApproved += $approved;
            $rows[] = [
                'pool_id' => $pool->id,
                'name' => $pool->name,
                'budget_amount' => $this->format($budget),
                'committed_amount' => $this->format($committed),
                'approved_amount' => $this->format($approved),
                'remaining_amount' => $this->format($budget - $committed),
                'utilization_percent' => $budget > 0 ? round($committed / $budget * 100, 1) : 0.0,
                'proposals_count' => $own->count(),
            ];
        }

        $approvedProposals = $proposals->where('status', CompProposal::Approved);
        $payroll = $this->sumCents($approvedProposals, 'current_salary');

        return [
            'cycle_id' => $cycle->id,
            'currency' => $cycle->currency,
            'budget_amount' => $this->format($totalBudget),
            'committed_amount' => $this->format($totalCommitted),
            'approved_amount' => $this->format($totalApproved),
            'remaining_amount' => $this->format($totalBudget - $totalCommitted),
            'approved_increase_percent' => $payroll > 0 ? round($totalApproved / $payroll * 100, 2) : 0.0,
            'proposals_by_status' => [
                CompProposal::Draft => $proposals->where('status', CompProposal::Draft)->count(),
                CompProposal::Submitted => $proposals->where('status', CompProposal::Submitted)->count(),
                CompProposal::Approved => $approvedProposals->count(),
                CompProposal::Rejected => $proposals->where('status', CompProposal::Rejected)->count(),
            ],
            'exceptions_count' => $proposals->where('is_exception', true)->where('status', '!=', CompProposal::Rejected)->count(),
            'pools' => $rows,
        ];
    }

    /**
     * Overview of the planning for the dashboard: open cycles and the totals of the most recent one.
     *
     * @return array<string, mixed>
     */
    public function overview(string $tenantId): array
    {
        $cycles = CompCycle::query()->where('tenant_id', $tenantId)->orderByDesc('fiscal_year')->orderByDesc('starts_on')->get();
        $current = $cycles->firstWhere('status', CompCycle::Open) ?? $cycles->first();

        return [
            'cycles_total' => $cycles->count(),
            'open_cycles' => $cycles->where('status', CompCycle::Open)->count(),
            'current_cycle' => $current === null ? null : ['id' => $current->id, 'name' => $current->name, 'status' => $current->status, ...$this->summary($current)],
        ];
    }

    /**
     * Amounts of a proposal, and the exception rule against the guideline of the rating.
     *
     * @return array{current_salary: string, increase_percent: string, increase_amount: string, new_salary: string, is_exception: bool}
     */
    private function figures(CompCycle $cycle, string $salary, int $rating, string $percent, mixed $justification): array
    {
        $guideline = $cycle->guidelines()->where('performance_rating', $rating)->first();
        $isException = $guideline === null || (float) $percent < (float) $guideline->min_percent || (float) $percent > (float) $guideline->max_percent;

        if ($isException && blank($justification)) {
            throw Rejection::on('justification', 'compensation.exception_needs_justification');
        }

        $salaryCents = $this->cents($salary);
        $increase = (int) round($salaryCents * (float) $percent / 100);

        return [
            'current_salary' => $this->format($salaryCents),
            'increase_percent' => number_format((float) $percent, 2, '.', ''),
            'increase_amount' => $this->format($increase),
            'new_salary' => $this->format($salaryCents + $increase),
            'is_exception' => $isException,
        ];
    }

    private function assertWithinBudget(CompProposal $proposal): void
    {
        $pool = CompBudgetPool::query()->whereKey($proposal->budget_pool_id)->lockForUpdate()->firstOrFail();
        $total = $this->committedCents($pool, $proposal) + $this->cents((string) $proposal->increase_amount);

        if ($total > $this->cents((string) $pool->budget_amount)) {
            throw Rejection::on('budget_pool_id', 'compensation.budget_exceeded', ['budget' => $this->format($this->cents((string) $pool->budget_amount)), 'total' => $this->format($total)]);
        }
    }

    private function committedCents(CompBudgetPool $pool, ?CompProposal $except): int
    {
        return $this->sumCents(CompProposal::query()
            ->where('budget_pool_id', $pool->id)
            ->whereIn('status', [CompProposal::Submitted, CompProposal::Approved])
            ->when($except !== null, static fn ($query) => $query->whereKeyNot($except->id))
            ->get());
    }

    /**
     * @param  iterable<CompProposal>  $proposals
     */
    private function sumCents(iterable $proposals, string $column = 'increase_amount'): int
    {
        $sum = 0;

        foreach ($proposals as $proposal) {
            $sum += $this->cents((string) $proposal->{$column});
        }

        return $sum;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertPoolFields(string $tenantId, CompCycle $cycle, array $data, ?CompBudgetPool $current): void
    {
        $departmentId = $data['department_id'] ?? null;

        if ($departmentId !== null && ! $this->directory->departmentExists($tenantId, (string) $departmentId)) {
            throw Rejection::on('department_id', 'compensation.department_missing');
        }

        if (isset($data['name'])) {
            $taken = CompBudgetPool::query()->where('cycle_id', $cycle->id)->where('name', $data['name'])
                ->when($current !== null, static fn ($query) => $query->whereKeyNot($current->id))->exists();

            if ($taken) {
                throw Rejection::on('name', 'compensation.pool_name_taken');
            }
        }
    }

    private function cents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function format(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }
}
