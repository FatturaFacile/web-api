<?php

declare(strict_types=1);

namespace Modules\Budgeting\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Models\BudgetLine;
use Modules\Budgeting\Models\BudgetVersion;

/**
 * Budget lines of a draft version. A line is identified by account, cost centre, project and period: saving the same
 * combination again replaces the amount. Accounts, cost centres and projects must exist in the company.
 */
final readonly class LineService
{
    public function __construct(private VersionService $versions, private LedgerLookup $ledger, private SpreadService $spread) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(BudgetVersion $version, array $data): BudgetLine
    {
        return DB::transaction(function () use ($version, $data): BudgetLine {
            $this->versions->assertDraft($version);
            $this->assertDimensions($version, $data);

            return $this->upsert($version, $data, (int) $data['period'], (string) $data['amount'], BudgetLine::Manual, null, $data['notes'] ?? null, $data['quantity'] ?? null, $data['unit_price'] ?? null);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(BudgetLine $line, array $data): BudgetLine
    {
        $version = BudgetVersion::query()->findOrFail($line->version_id);
        $this->versions->assertDraft($version);
        $merged = [...$line->only(['account_id', 'cost_center_id', 'project_id', 'period']), ...$data];
        $this->assertDimensions($version, $merged);

        $other = $this->find($version, (string) $merged['account_id'], $merged['cost_center_id'] ?? null, $merged['project_id'] ?? null, (int) $merged['period']);

        if ($other !== null && $other->id !== $line->id) {
            throw ValidationException::withMessages(['period' => [__('budgeting_errors.line.duplicate')]]);
        }

        $line->update([
            'account_id' => $merged['account_id'],
            'cost_center_id' => $merged['cost_center_id'] ?? null,
            'project_id' => $merged['project_id'] ?? null,
            'period' => (int) $merged['period'],
            ...array_intersect_key($data, array_flip(['amount', 'notes', 'quantity', 'unit_price'])),
            'source' => BudgetLine::Manual,
            'driver_id' => null,
        ]);

        return $line->refresh();
    }

    public function delete(BudgetLine $line): void
    {
        $this->versions->assertDraft(BudgetVersion::query()->findOrFail($line->version_id));
        $line->delete();
    }

    /**
     * Spreads an annual (or part-year) amount over a range of periods of one account, cost centre and project, replacing
     * the lines already there.
     *
     * @param  array<string, mixed>  $data
     * @return list<BudgetLine>
     */
    public function spread(BudgetVersion $version, array $data): array
    {
        return DB::transaction(function () use ($version, $data): array {
            $this->versions->assertDraft($version);
            $this->assertDimensions($version, $data);

            $amounts = $this->spread->spread(
                bcadd((string) $data['total_amount'], '0', 2),
                (int) $data['start_period'],
                (int) $data['end_period'],
                (string) $data['method'],
                $version->seasonal_weights,
                $data['percentages'] ?? null,
            );

            $lines = [];
            foreach ($amounts as $period => $amount) {
                $lines[] = $this->upsert($version, $data, $period, $amount, BudgetLine::Spread, null, $data['notes'] ?? null);
            }

            return $lines;
        });
    }

    /**
     * Writes the amount of a period of a combination, replacing the line that is there.
     *
     * @param  array<string, mixed>  $key
     */
    public function upsert(BudgetVersion $version, array $key, int $period, string $amount, string $source, ?string $driverId, ?string $notes, ?string $quantity = null, ?string $unitPrice = null): BudgetLine
    {
        $line = $this->find($version, (string) $key['account_id'], $key['cost_center_id'] ?? null, $key['project_id'] ?? null, $period);
        $values = ['amount' => $amount, 'source' => $source, 'driver_id' => $driverId, 'notes' => $notes, 'quantity' => $quantity, 'unit_price' => $unitPrice];

        if ($line === null) {
            return BudgetLine::create([
                'tenant_id' => $version->tenant_id,
                'version_id' => $version->id,
                'account_id' => $key['account_id'],
                'cost_center_id' => $key['cost_center_id'] ?? null,
                'project_id' => $key['project_id'] ?? null,
                'period' => $period,
                ...$values,
            ])->refresh();
        }

        $line->update($values);

        return $line->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertDimensions(BudgetVersion $version, array $data): void
    {
        $period = (int) ($data['period'] ?? $data['start_period'] ?? 1);

        if ($period < 1 || $period > BudgetPeriods::Count) {
            throw ValidationException::withMessages(['period' => [__('budgeting_errors.line.period_range')]]);
        }

        if ($this->ledger->accounts($version->tenant_id, [(string) $data['account_id']]) === []) {
            throw ValidationException::withMessages(['account_id' => [__('budgeting_errors.line.account_missing')]]);
        }

        if (! empty($data['cost_center_id']) && $this->ledger->costCenters($version->tenant_id, [(string) $data['cost_center_id']]) === []) {
            throw ValidationException::withMessages(['cost_center_id' => [__('budgeting_errors.line.cost_center_missing')]]);
        }

        if (! empty($data['project_id']) && $this->ledger->projects($version->tenant_id, [(string) $data['project_id']]) === []) {
            throw ValidationException::withMessages(['project_id' => [__('budgeting_errors.line.project_missing')]]);
        }
    }

    private function find(BudgetVersion $version, string $accountId, mixed $costCenterId, mixed $projectId, int $period): ?BudgetLine
    {
        return BudgetLine::query()
            ->where('version_id', $version->id)
            ->where('account_id', $accountId)
            ->where('period', $period)
            ->when($costCenterId === null || $costCenterId === '', static fn ($query) => $query->whereNull('cost_center_id'), static fn ($query) => $query->where('cost_center_id', $costCenterId))
            ->when($projectId === null || $projectId === '', static fn ($query) => $query->whereNull('project_id'), static fn ($query) => $query->where('project_id', $projectId))
            ->first();
    }
}
