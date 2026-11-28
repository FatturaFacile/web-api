<?php

declare(strict_types=1);

namespace Modules\TimeTravel\Services\Projections;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\TimeTravel\Domain\Enums\AggregateType;
use Modules\TimeTravel\Domain\Enums\QueryDomain;
use Modules\TimeTravel\Models\BiTemporalEvent;
use Modules\TimeTravel\Support\Amount;
use Modules\TimeTravel\Support\Instant;

/**
 * General ledger: trial balance (debit and credit per account) and account ledger (the entries of one account with the running balance).
 * Event payload: journal_entry_id, entry_number, posting_date, description, ledger_type, source_document_type, source_document_id and
 * lines [{account_id, debit, credit}].
 */
final class GeneralLedgerProjector implements StateProjector
{
    public const string DEFAULT_LEDGER = 'statutory';

    public function aggregateType(): AggregateType
    {
        return AggregateType::GeneralLedger;
    }

    public function checkpointDomain(): QueryDomain
    {
        return QueryDomain::TrialBalance;
    }

    public function usesCheckpoint(QueryDomain $domain): bool
    {
        return $domain === QueryDomain::TrialBalance;
    }

    public function initialState(QueryDomain $domain, array $filters): array
    {
        if ($domain === QueryDomain::AccountLedger) {
            return ['entries' => [], 'account_id' => (string) ($filters['account_id'] ?? ''), 'ledger_type' => $this->ledger($filters)];
        }

        return ['accounts' => []];
    }

    public function apply(array $state, BiTemporalEvent $event): array
    {
        $payload = $event->payload;
        $ledger = (string) ($payload['ledger_type'] ?? self::DEFAULT_LEDGER);
        $lines = $this->lines($payload);

        if (array_key_exists('entries', $state)) {
            if ($ledger !== $state['ledger_type']) {
                return $state;
            }

            $debit = '0';
            $credit = '0';
            $touched = false;

            foreach ($lines as $line) {
                if ((string) ($line['account_id'] ?? '') === $state['account_id']) {
                    $debit = Amount::add($debit, $line['debit'] ?? 0);
                    $credit = Amount::add($credit, $line['credit'] ?? 0);
                    $touched = true;
                }
            }

            if ($touched) {
                $state['entries'][] = [
                    'entry_id' => (string) ($payload['journal_entry_id'] ?? $event->aggregate_id),
                    'entry_number' => (string) ($payload['entry_number'] ?? ''),
                    'posting_date' => (string) ($payload['posting_date'] ?? substr((string) $event->valid_at, 0, 10)),
                    'description' => (string) ($payload['description'] ?? ''),
                    'debit' => $debit,
                    'credit' => $credit,
                    'valid_at' => (string) $event->valid_at,
                    'recorded_at' => (string) $event->recorded_at,
                    'sequence' => $event->sequence_number,
                ];
            }

            return $state;
        }

        foreach ($lines as $line) {
            $account = (string) ($line['account_id'] ?? '');

            if ($account === '') {
                continue;
            }

            $current = $state['accounts'][$ledger][$account] ?? ['debit' => '0', 'credit' => '0'];
            $state['accounts'][$ledger][$account] = [
                'debit' => Amount::add($current['debit'], $line['debit'] ?? 0),
                'credit' => Amount::add($current['credit'], $line['credit'] ?? 0),
            ];
        }

        return $state;
    }

    public function render(QueryDomain $domain, array $state, array $filters, CarbonImmutable $validAt, string $tenantId): array
    {
        return $domain === QueryDomain::AccountLedger
            ? $this->renderLedger($state)
            : $this->renderTrialBalance($state, $filters, $tenantId);
    }

    public function comparable(QueryDomain $domain, array $rows): array
    {
        $comparable = [];

        foreach ($rows as $row) {
            if ($domain === QueryDomain::AccountLedger) {
                $value = Amount::money(Amount::sub($row['debit'], $row['credit']));
                $comparable[(string) $row['entry_id']] = ['label' => (string) $row['entry_number'], 'value' => $value, 'weight' => $value];

                continue;
            }

            $label = trim(((string) ($row['account_code'] ?? '')).' '.((string) ($row['account_name'] ?? '')));
            $comparable[(string) $row['account_id']] = [
                'label' => $label !== '' ? $label : (string) $row['account_id'],
                'value' => (string) $row['balance'],
                'weight' => Amount::cmp($row['balance'], 0) > 0 ? (string) $row['balance'] : Amount::money(0),
            ];
        }

        return $comparable;
    }

    public function matches(QueryDomain $domain, BiTemporalEvent $event, array $filters): bool
    {
        if ((string) ($event->payload['ledger_type'] ?? self::DEFAULT_LEDGER) !== $this->ledger($filters)) {
            return false;
        }

        $account = (string) ($filters['account_id'] ?? '');

        if ($account === '' && $domain !== QueryDomain::AccountLedger) {
            return true;
        }

        return array_any($this->lines($event->payload), static fn (array $line): bool => (string) ($line['account_id'] ?? '') === $account);
    }

    public function affectedKeys(QueryDomain $domain, BiTemporalEvent $event, array $filters): array
    {
        if ($domain === QueryDomain::AccountLedger) {
            return [(string) ($event->payload['journal_entry_id'] ?? $event->aggregate_id)];
        }

        $keys = [];

        foreach ($this->lines($event->payload) as $line) {
            $keys[(string) ($line['account_id'] ?? '')] = true;
        }

        return array_map('strval', array_keys($keys));
    }

    public function amountDelta(QueryDomain $domain, BiTemporalEvent $event, array $filters): string
    {
        $account = (string) ($filters['account_id'] ?? '');
        $delta = '0';

        foreach ($this->lines($event->payload) as $line) {
            if ($account !== '' && (string) ($line['account_id'] ?? '') !== $account) {
                continue;
            }

            // Without an account the effect on the trial balance is the debit side of the entry.
            $delta = $account !== ''
                ? Amount::add($delta, Amount::sub($line['debit'] ?? 0, $line['credit'] ?? 0))
                : Amount::add($delta, $line['debit'] ?? 0);
        }

        return Amount::money($delta);
    }

    public function sourceDocument(BiTemporalEvent $event): ?string
    {
        $type = (string) ($event->payload['source_document_type'] ?? '');
        $id = (string) ($event->payload['source_document_id'] ?? '');
        $number = (string) ($event->payload['entry_number'] ?? '');

        if ($type !== '' && $id !== '') {
            return $type.':'.$id;
        }

        return $number !== '' ? 'journal_entry:'.$number : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function ledger(array $filters): string
    {
        $ledger = (string) ($filters['ledger_type'] ?? '');

        return $ledger !== '' ? $ledger : self::DEFAULT_LEDGER;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function lines(array $payload): array
    {
        $lines = $payload['lines'] ?? [];

        return is_array($lines) ? array_values(array_filter($lines, is_array(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function renderTrialBalance(array $state, array $filters, string $tenantId): array
    {
        /** @var array<string, array{debit: string, credit: string}> $accounts */
        $accounts = $state['accounts'][$this->ledger($filters)] ?? [];
        $names = $accounts === [] ? collect() : DB::table('chart_of_accounts')
            ->whereIn('id', array_keys($accounts))
            ->where(fn ($query) => $query->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->get(['id', 'account_code', 'code', 'name'])
            ->keyBy('id');

        $rows = [];
        $debit = '0';
        $credit = '0';

        foreach ($accounts as $accountId => $sums) {
            $balance = Amount::sub($sums['debit'], $sums['credit']);

            if (Amount::isZero($sums['debit']) && Amount::isZero($sums['credit'])) {
                continue;
            }

            $account = $names->get($accountId);
            $rows[] = [
                'account_id' => (string) $accountId,
                'account_code' => $account !== null ? ($account->account_code ?? $account->code) : null,
                'account_name' => $account?->name,
                'debit' => Amount::money($sums['debit']),
                'credit' => Amount::money($sums['credit']),
                'balance' => Amount::money($balance),
            ];
            $debit = Amount::add($debit, $sums['debit']);
            $credit = Amount::add($credit, $sums['credit']);
        }

        usort($rows, static fn (array $a, array $b): int => [(string) ($a['account_code'] ?? $a['account_id']), $a['account_id']] <=> [(string) ($b['account_code'] ?? $b['account_id']), $b['account_id']]);

        return ['rows' => $rows, 'totals' => ['debit' => Amount::money($debit), 'credit' => Amount::money($credit), 'balance' => Amount::money(Amount::sub($debit, $credit))]];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function renderLedger(array $state): array
    {
        /** @var list<array<string, mixed>> $entries */
        $entries = $state['entries'];
        usort($entries, static fn (array $a, array $b): int => [$a['posting_date'], $a['valid_at'], $a['sequence']] <=> [$b['posting_date'], $b['valid_at'], $b['sequence']]);

        $rows = [];
        $running = '0';
        $debit = '0';
        $credit = '0';

        foreach ($entries as $entry) {
            $running = Amount::add($running, Amount::sub($entry['debit'], $entry['credit']));
            $debit = Amount::add($debit, $entry['debit']);
            $credit = Amount::add($credit, $entry['credit']);
            $rows[] = [
                'entry_id' => $entry['entry_id'],
                'entry_number' => $entry['entry_number'],
                'posting_date' => $entry['posting_date'],
                'description' => $entry['description'],
                'debit' => Amount::money($entry['debit']),
                'credit' => Amount::money($entry['credit']),
                'running_balance' => Amount::money($running),
                'recorded_at' => Instant::iso(Instant::from((string) $entry['recorded_at'])),
            ];
        }

        return ['rows' => $rows, 'totals' => ['debit' => Amount::money($debit), 'credit' => Amount::money($credit), 'balance' => Amount::money($running)]];
    }
}
