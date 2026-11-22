<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\AnalyticalAccounting\Domain\Enums\CostElementType;
use Modules\AnalyticalAccounting\Domain\Enums\EntryType;
use Modules\AnalyticalAccounting\Domain\Services\AllocationVectorSplitter;
use Modules\AnalyticalAccounting\Domain\Services\AnalyticalDerivationService;
use Modules\AnalyticalAccounting\Domain\Services\HierarchyValidatorService;
use Modules\AnalyticalAccounting\Models\AnalyticalCenter;
use Modules\AnalyticalAccounting\Models\AnalyticalEntry;
use Modules\AnalyticalAccounting\Models\AnalyticalEntryLine;
use Modules\AnalyticalAccounting\Models\ApportionmentRun;
use Modules\AnalyticalAccounting\Models\DerivationRule;
use Modules\AnalyticalAccounting\Support\Money;

/**
 * Writes the management (analytical) ledger. It never touches the statutory general ledger: the link to a journal entry
 * or an invoice is only a correlation id. Lines can carry several allocation vectors, missing dimensions are derived by
 * the rules, and postings are accepted only on active centers inside their validity window.
 */
final class AnalyticalPostingService
{
    public function __construct(
        private readonly AnalyticalDerivationService $derivation,
        private readonly AllocationVectorSplitter $splitter,
        private readonly HierarchyValidatorService $hierarchy,
    ) {}

    /**
     * @param  array<string, mixed>  $header  entry_type, posting_date, description, currency, source_type, source_id, gl_journal_entry_id, invoice_id
     * @param  list<array<string, mixed>>  $lines  amount (signed), cost_element_type, dimensions, optional vectors, derivation criteria
     */
    public function post(string $tenantId, array $header, array $lines, ?string $userId = null, bool $deriveMissing = true): AnalyticalEntry
    {
        if ($lines === []) {
            throw new InvalidArgumentException('An analytical entry needs at least one line.');
        }
        $entryType = EntryType::from((string) $header['entry_type']);
        $date = Carbon::parse((string) $header['posting_date'])->toDateString();
        $periodKey = substr($date, 0, 7);

        if ($entryType === EntryType::MANUAL_ADJUSTMENT && $this->periodConsolidated($tenantId, $periodKey)) {
            throw new InvalidArgumentException("Period {$periodKey} is consolidated: reverse its apportionment run before posting manual adjustments.");
        }

        $rules = $deriveMissing ? $this->rules($tenantId) : [];
        $centers = $this->centerDefaults($tenantId);
        $prepared = [];
        foreach ($lines as $line) {
            foreach ($this->expand($line) as $part) {
                $prepared[] = $this->resolveLine($tenantId, $part, $date, $rules, $centers);
            }
        }

        return DB::transaction(function () use ($tenantId, $header, $entryType, $date, $periodKey, $prepared, $userId): AnalyticalEntry {
            $entry = AnalyticalEntry::create([
                'tenant_id' => $tenantId,
                'entry_type' => $entryType->value,
                'posting_date' => $date,
                'period_key' => $periodKey,
                'currency' => $header['currency'] ?? 'EUR',
                'description' => $header['description'] ?? null,
                'source_type' => $header['source_type'] ?? null,
                'source_id' => $header['source_id'] ?? null,
                'gl_journal_entry_id' => $header['gl_journal_entry_id'] ?? null,
                'invoice_id' => $header['invoice_id'] ?? null,
                'apportionment_run_id' => $header['apportionment_run_id'] ?? null,
                'created_by' => $userId,
            ]);
            foreach ($prepared as $i => $line) {
                AnalyticalEntryLine::create($line + [
                    'tenant_id' => $tenantId,
                    'analytical_entry_id' => $entry->id,
                    'line_number' => $i + 1,
                    'posting_date' => $date,
                    'period_key' => $periodKey,
                    'created_by' => $userId,
                ]);
            }

            return $entry->load('lines');
        });
    }

    /**
     * Compensating entry: the original stays in the book flagged as reversed, the mirror lines net it to zero.
     */
    public function reverse(AnalyticalEntry $entry, ?string $userId = null, ?string $reason = null): AnalyticalEntry
    {
        if ($entry->is_reversed || $entry->entry_type === EntryType::REVERSAL->value) {
            throw new InvalidArgumentException('The entry is already reversed or is itself a reversal.');
        }
        if ($entry->entry_type === EntryType::STEP_DOWN_APPORTIONMENT->value) {
            throw new InvalidArgumentException('Apportionment entries are reversed together with their run.');
        }

        return DB::transaction(function () use ($entry, $userId, $reason): AnalyticalEntry {
            $reversal = $this->mirror($entry, $userId, $reason);
            $entry->forceFill(['is_reversed' => true, 'reversed_by_entry_id' => $reversal->id])->save();

            return $reversal;
        });
    }

    /**
     * Mirrors every entry of a consolidated run and flags the originals as reversed.
     */
    public function reverseRunEntries(ApportionmentRun $run, ?string $userId = null, ?string $reason = null): int
    {
        $count = 0;
        $entries = AnalyticalEntry::query()->where('tenant_id', $run->tenant_id)->where('apportionment_run_id', $run->id)
            ->where('entry_type', EntryType::STEP_DOWN_APPORTIONMENT->value)->where('is_reversed', false)->get();
        foreach ($entries as $entry) {
            $reversal = $this->mirror($entry, $userId, $reason);
            $entry->forceFill(['is_reversed' => true, 'reversed_by_entry_id' => $reversal->id])->save();
            $count++;
        }

        return $count;
    }

    /**
     * Preview of the dimensions the rules would give to a posting line.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, string|null>  $explicit
     * @return array{dimensions: array<string, string|null>, provenance: array<string, string>, source: string, rule_ids: list<string>}
     */
    public function testDerivation(string $tenantId, array $context, array $explicit = []): array
    {
        $centers = $this->centerDefaults($tenantId);

        return $this->derivation->resolve($this->rules($tenantId), $context + ['date' => date('Y-m-d')], $explicit, $centers);
    }

    public function periodConsolidated(string $tenantId, string $periodKey): bool
    {
        return ApportionmentRun::query()->where('tenant_id', $tenantId)->where('period_key', $periodKey)->where('status', 'CONSOLIDATED')->exists();
    }

    private function mirror(AnalyticalEntry $entry, ?string $userId, ?string $reason): AnalyticalEntry
    {
        $mirror = AnalyticalEntry::create([
            'tenant_id' => $entry->tenant_id, 'entry_type' => EntryType::REVERSAL->value, 'posting_date' => $entry->posting_date,
            'period_key' => $entry->period_key, 'currency' => $entry->currency,
            'description' => $reason ?? 'Reversal of '.$entry->id, 'source_type' => $entry->source_type, 'source_id' => $entry->source_id,
            'gl_journal_entry_id' => $entry->gl_journal_entry_id, 'invoice_id' => $entry->invoice_id,
            'apportionment_run_id' => $entry->apportionment_run_id, 'created_by' => $userId,
        ]);
        foreach ($entry->lines()->get() as $line) {
            AnalyticalEntryLine::create([
                'tenant_id' => $entry->tenant_id, 'analytical_entry_id' => $mirror->id, 'line_number' => $line->line_number,
                'cost_center_id' => $line->cost_center_id, 'profit_center_id' => $line->profit_center_id, 'project_id' => $line->project_id,
                'product_line_id' => $line->product_line_id, 'cost_element_type' => $line->cost_element_type,
                'amount' => bcmul((string) $line->amount, '-1', 4), 'posting_date' => $line->posting_date, 'period_key' => $line->period_key,
                'derivation_source' => $line->derivation_source, 'apportionment_step_id' => $line->apportionment_step_id,
                'description' => $line->description, 'created_by' => $userId,
            ]);
        }

        return $mirror;
    }

    /**
     * A line with vectors becomes one line per vector; a plain line stays as it is.
     *
     * @param  array<string, mixed>  $line
     * @return list<array<string, mixed>>
     */
    private function expand(array $line): array
    {
        $vectors = $line['vectors'] ?? [];
        unset($line['vectors']);
        if (! is_array($vectors) || $vectors === []) {
            $line['amount_cents'] = Money::toCents((string) $line['amount']);

            return [$line];
        }
        $parts = [];
        foreach ($this->splitter->split(Money::toCents((string) $line['amount']), array_values($vectors)) as $vector) {
            $parts[] = [...$line, ...array_intersect_key($vector, array_flip(AnalyticalDerivationService::Dimensions)), 'amount_cents' => $vector['amount_cents'], 'split_percentage' => $vector['split_percentage']];
        }

        return $parts;
    }

    /**
     * @param  array<string, mixed>  $part
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, array<string, string|null>>  $centers
     * @return array<string, mixed>
     */
    private function resolveLine(string $tenantId, array $part, string $date, array $rules, array $centers): array
    {
        $explicit = array_intersect_key($part, array_flip(AnalyticalDerivationService::Dimensions));
        $context = array_intersect_key($part, array_flip(AnalyticalDerivationService::Criteria)) + ['date' => $date];
        $resolved = $this->derivation->resolve($rules, $context, array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $explicit), $centers);
        $dimensions = $resolved['dimensions'];

        if ($dimensions['cost_center_id'] === null && $dimensions['profit_center_id'] === null && ($dimensions['project_id'] ?? null) === null) {
            throw new InvalidArgumentException('A line needs at least a cost center, a profit center or a project.');
        }
        $elementType = $dimensions['cost_element_type'] ?? ($part['fallback_cost_element_type'] ?? null);
        if ($elementType === null || CostElementType::tryFrom($elementType) === null) {
            throw new InvalidArgumentException('A line needs a valid cost element type.');
        }
        foreach (['cost_center_id', 'profit_center_id'] as $key) {
            if ($dimensions[$key] !== null) {
                $this->assertPostable($tenantId, $dimensions[$key], $date);
            }
        }

        return [
            'cost_center_id' => $dimensions['cost_center_id'],
            'profit_center_id' => $dimensions['profit_center_id'],
            'project_id' => $dimensions['project_id'],
            'product_line_id' => $dimensions['product_line_id'],
            'department_id' => $part['department_id'] ?? null,
            'location_id' => $part['location_id'] ?? null,
            'cost_element_type' => $elementType,
            'amount' => Money::fromCents((int) $part['amount_cents']),
            'split_percentage' => $part['split_percentage'] ?? null,
            'derivation_source' => $resolved['source'],
            'derivation_meta' => ['provenance' => $resolved['provenance'], 'rule_ids' => $resolved['rule_ids']],
            'gl_journal_line_id' => $part['gl_journal_line_id'] ?? null,
            'invoice_line_id' => $part['invoice_line_id'] ?? null,
            'description' => $part['description'] ?? null,
        ];
    }

    private function assertPostable(string $tenantId, string $centerId, string $date): void
    {
        $center = AnalyticalCenter::query()->where('tenant_id', $tenantId)->find($centerId);
        if ($center === null) {
            throw new InvalidArgumentException("Center {$centerId} does not exist.");
        }
        $accepts = $this->hierarchy->acceptsPostings([
            'is_active' => (bool) $center->is_active,
            'valid_from' => $center->valid_from?->toDateString(),
            'valid_to' => $center->valid_to?->toDateString(),
        ], $date);
        if (! $accepts) {
            throw new InvalidArgumentException("Center {$center->code} is not active on {$date}.");
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rules(string $tenantId): array
    {
        return DerivationRule::query()->where('tenant_id', $tenantId)->where('is_active', true)->get()->map(static function (DerivationRule $rule): array {
            $row = $rule->getAttributes();
            $row['valid_from'] = $rule->valid_from?->toDateString();
            $row['valid_to'] = $rule->valid_to?->toDateString();

            return $row;
        })->all();
    }

    /**
     * Master defaults of the centers, indexed by id.
     *
     * @return array<string, array<string, string|null>>
     */
    private function centerDefaults(string $tenantId): array
    {
        $out = [];
        foreach (AnalyticalCenter::query()->where('tenant_id', $tenantId)->get(['id', 'default_profit_center_id', 'default_project_id', 'default_product_line_id', 'default_cost_element_type']) as $c) {
            $out[(string) $c->id] = [
                'default_profit_center_id' => $c->default_profit_center_id,
                'default_project_id' => $c->default_project_id,
                'default_product_line_id' => $c->default_product_line_id,
                'default_cost_element_type' => $c->default_cost_element_type,
            ];
        }

        return $out;
    }
}
