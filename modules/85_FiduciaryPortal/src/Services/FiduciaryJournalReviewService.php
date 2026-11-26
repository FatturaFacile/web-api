<?php

declare(strict_types=1);

namespace Modules\FiduciaryPortal\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Models\ChartOfAccount;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\JournalLine;
use Modules\GeneralLedger\Services\LedgerReportService;

final readonly class FiduciaryJournalReviewService
{
    public function __construct(
        private FiduciaryAuditService $audit,
        private FiduciaryTranscodingService $transcoding,
        private LedgerReportService $ledgerReports,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function listJournals(string $tenantId, array $filters): array
    {
        $studioSystem = (string) ($filters['studio_system'] ?? 'universal');
        $transcodingMap = $this->transcoding->mapForTenant($tenantId, $studioSystem);

        $query = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with([
                'fiscalPeriod:id,fiscal_year_id,period_number,period_name,status,fiduciary_locked_at',
                'lines' => fn ($q) => $q->withoutGlobalScopes()->with(['account:id,account_code,name,standard', 'vatCode:id,code,rate'])->orderBy('line_number'),
            ])
            ->withCount(['fiduciaryNotes as open_notes_count' => fn ($q) => $q->where('status', 'open')])
            ->when(! empty($filters['fiscal_year_id']), fn ($q) => $q->whereHas('fiscalPeriod', fn ($p) => $p->where('fiscal_year_id', (string) $filters['fiscal_year_id'])))
            ->when(! empty($filters['fiscal_period_id']), fn ($q) => $q->where('fiscal_period_id', (string) $filters['fiscal_period_id']))
            ->when(! empty($filters['month']), function ($q) use ($filters): void {
                $month = (int) $filters['month'];
                if ($month >= 1 && $month <= 12) {
                    $q->whereHas('fiscalPeriod', fn ($p) => $p->where('period_number', $month));
                }
            })
            ->when(! empty($filters['from']), fn ($q) => $q->whereDate('posting_date', '>=', (string) $filters['from']))
            ->when(! empty($filters['to']), fn ($q) => $q->whereDate('posting_date', '<=', (string) $filters['to']))
            ->when(! empty($filters['journal_type']), fn ($q) => $q->where('journal_type', (string) $filters['journal_type']))
            ->when(! empty($filters['status']) && $filters['status'] !== 'all', fn ($q) => $q->where('status', (string) $filters['status']))
            ->when(isset($filters['fiduciary_verified']) && $filters['fiduciary_verified'] !== 'all' && $filters['fiduciary_verified'] !== '', function ($q) use ($filters): void {
                $val = $filters['fiduciary_verified'];
                $verified = $val === 'verified' || $val === true || $val === '1' || $val === 'true';
                $q->where('fiduciary_verified', $verified);
            })
            ->when(! empty($filters['has_open_notes']), fn ($q) => $q->whereHas('fiduciaryNotes', fn ($n) => $n->where('status', 'open')))
            ->when(! empty($filters['account_code_from']) || ! empty($filters['account_code_to']), function ($q) use ($filters): void {
                $from = (string) ($filters['account_code_from'] ?? '');
                $to = (string) ($filters['account_code_to'] ?? '');
                $q->whereHas('lines.account', function ($acc) use ($from, $to): void {
                    if ($from !== '') {
                        $acc->where('account_code', '>=', $from);
                    }
                    if ($to !== '') {
                        $acc->where('account_code', '<=', $to);
                    }
                });
            })
            ->when(! empty($filters['search']), function ($q) use ($filters): void {
                $needle = '%'.mb_strtolower(trim((string) $filters['search'])).'%';
                $q->where(function ($sub) use ($needle): void {
                    $sub->whereRaw('LOWER(entry_number) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(COALESCE(document_number, \'\')) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$needle]);
                });
            })
            ->orderByDesc('posting_date')
            ->orderByDesc('journal_number');

        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));
        $paginator = $query->paginate($perPage);

        $items = $paginator->getCollection()->map(function (JournalEntry $entry) use ($transcodingMap): array {
            $lines = $entry->lines->map(function (JournalLine $line) use ($transcodingMap): array {
                $rule = $transcodingMap[(string) $line->account_id] ?? null;

                return [
                    'id' => (string) $line->id,
                    'line_number' => (int) $line->line_number,
                    'account_id' => (string) $line->account_id,
                    'account_code' => $line->account?->account_code,
                    'account_name' => $line->account?->name,
                    'studio_account_code' => $rule?->studio_account_code,
                    'studio_account_name' => $rule?->studio_account_name,
                    'description' => $line->description,
                    'debit_amount' => (string) $line->debit_amount,
                    'credit_amount' => (string) $line->credit_amount,
                    'functional_debit' => (string) ($line->functional_debit ?? $line->debit_amount),
                    'functional_credit' => (string) ($line->functional_credit ?? $line->credit_amount),
                    'currency' => (string) $line->currency,
                    'exchange_rate' => (string) $line->exchange_rate,
                    'vat_code' => $line->vatCode?->code,
                    'vat_rate_percent' => $line->vat_rate_percent,
                ];
            })->values()->all();

            return [
                'id' => (string) $entry->id,
                'entry_number' => (string) $entry->entry_number,
                'journal_number' => $entry->journal_number,
                'document_number' => $entry->getAttribute('document_number'),
                'fiscal_period_id' => (string) $entry->fiscal_period_id,
                'period_name' => $entry->fiscalPeriod?->period_name,
                'period_status' => $entry->fiscalPeriod?->status,
                'is_period_fiduciary_locked' => $entry->fiscalPeriod?->isFiduciaryLocked() ?? false,
                'posting_date' => $entry->posting_date?->toDateString(),
                'document_date' => $entry->document_date?->toDateString(),
                'ledger_type' => (string) $entry->ledger_type,
                'journal_type' => (string) $entry->journal_type,
                'description' => (string) $entry->description,
                'status' => (string) $entry->status,
                'total_amount' => (string) ($entry->total_amount ?? '0.0000'),
                'fiduciary_verified' => (bool) $entry->getAttribute('fiduciary_verified'),
                'fiduciary_verified_at' => $entry->getAttribute('fiduciary_verified_at')?->toIso8601String(),
                'fiduciary_verified_by' => $entry->getAttribute('fiduciary_verified_by'),
                'fiduciary_verification_note' => $entry->getAttribute('fiduciary_verification_note'),
                'open_notes_count' => (int) ($entry->getAttribute('open_notes_count') ?? 0),
                'lines' => $lines,
            ];
        })->values()->all();

        return [
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * @param  list<string>  $entryIds
     * @return array{updated: int, verified: bool, entry_ids: list<string>}
     */
    public function verifyEntries(string $tenantId, array $entryIds, bool $verified, ?string $note, User $actor): array
    {
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $entryIds)
            ->get();

        $updatedIds = [];

        DB::transaction(function () use ($entries, $verified, $note, $actor, &$updatedIds): void {
            foreach ($entries as $entry) {
                $entry->update([
                    'fiduciary_verified' => $verified,
                    'fiduciary_verified_at' => $verified ? now() : null,
                    'fiduciary_verified_by' => $verified ? (string) $actor->getKey() : null,
                    'fiduciary_verification_note' => $verified ? $note : null,
                ]);
                $updatedIds[] = (string) $entry->id;
            }
        });

        $isBatch = count($updatedIds) > 1;
        $action = $verified
            ? ($isBatch ? 'batch_verified' : 'entry_verified')
            : ($isBatch ? 'batch_unverified' : 'entry_unverified');

        $this->audit->record(
            tenantId: $tenantId,
            action: $action,
            summary: ($verified ? 'Marked ' : 'Unmarked ').count($updatedIds).' journal '.(count($updatedIds) === 1 ? 'entry' : 'entries').' as fiduciary verified.',
            actor: $actor,
            targetType: 'journal_entry',
            targetId: count($updatedIds) === 1 ? $updatedIds[0] : null,
            targetReference: count($updatedIds) === 1 ? (string) $entries->first()?->entry_number : count($updatedIds).' entries',
            metadata: ['verified' => $verified, 'note' => $note, 'entry_ids' => $updatedIds],
            auditModel: count($updatedIds) === 1 ? $entries->first() : null,
        );

        return [
            'updated' => count($updatedIds),
            'verified' => $verified,
            'entry_ids' => $updatedIds,
        ];
    }

    /**
     * General Ledger (Mastrino Contabile) enriched with studio account transcoding and fiduciary verification flags.
     *
     * @return array<string, mixed>
     */
    public function inspectAccountLedger(
        string $tenantId,
        string $accountId,
        string $from,
        string $to,
        int $page = 1,
        int $perPage = 100,
        string $studioSystem = 'universal',
    ): array {
        $account = ChartOfAccount::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->findOrFail($accountId);

        $report = $this->ledgerReports->accountLedger(
            $tenantId,
            $account,
            CarbonImmutable::parse($from),
            CarbonImmutable::parse($to),
            $page,
            $perPage
        );

        $transcodingMap = $this->transcoding->mapForTenant($tenantId, $studioSystem);
        $rule = $transcodingMap[(string) $account->id] ?? null;

        $entryIds = array_values(array_unique(array_map(
            static fn (array $line): string => (string) $line['journal_entry_id'],
            $report['lines']
        )));

        $verificationByEntry = $entryIds === []
            ? collect()
            : JournalEntry::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $entryIds)
                ->withCount(['fiduciaryNotes as open_notes_count' => fn ($q) => $q->where('status', 'open')])
                ->get(['id', 'fiduciary_verified', 'fiduciary_verified_at'])
                ->keyBy('id');

        $report['account']['studio_account_code'] = $rule?->studio_account_code;
        $report['account']['studio_account_name'] = $rule?->studio_account_name;
        $report['studio_system'] = $studioSystem;

        $report['lines'] = array_map(function (array $line) use ($verificationByEntry): array {
            $je = $verificationByEntry->get((string) $line['journal_entry_id']);
            $line['fiduciary_verified'] = (bool) ($je?->getAttribute('fiduciary_verified') ?? false);
            $line['open_notes_count'] = (int) ($je?->getAttribute('open_notes_count') ?? 0);

            return $line;
        }, $report['lines']);

        return $report;
    }
}
