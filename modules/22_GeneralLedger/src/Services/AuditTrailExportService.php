<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

/**
 * The audit trail of the ledger for auditors (revisore, Revisionsstelle): every journal entry of a range of posting
 * dates, whatever its status, with who wrote, submitted, approved, rejected, posted and reversed it and when, its link
 * to the source document and to its reversal, and the debit and credit totals of its lines. Read in chunks, so the
 * memory does not grow with the range.
 */
final class AuditTrailExportService
{
    /**
     * @var list<string>
     */
    public const array Header = [
        'journal_entry_id', 'journal_number', 'entry_number', 'posting_date', 'document_date', 'journal_type', 'ledger_type', 'status',
        'source_document_type', 'source_document_id', 'document_number', 'description', 'total_debit', 'total_credit', 'line_count',
        'created_by', 'created_by_name', 'created_at', 'submitted_by', 'submitted_at', 'approved_by', 'approved_by_name', 'approved_at',
        'rejected_by', 'rejected_at', 'rejection_reason', 'posted_by', 'posted_by_name', 'posted_at', 'reversed_by', 'reversed_at',
        'reversal_journal_entry_id', 'auto_reverse_on',
    ];

    /**
     * The rows of the range, ordered by posting date and libro giornale number.
     *
     * @return Generator<int, array<string, string|int|null>>
     */
    public function rows(string $tenantId, CarbonImmutable $from, CarbonImmutable $to): Generator
    {
        $totals = DB::table('journal_lines')
            ->whereNull('deleted_at')
            ->where('tenant_id', $tenantId)
            ->groupBy('journal_entry_id')
            ->selectRaw('journal_entry_id, SUM(COALESCE(functional_debit, debit_amount)) as total_debit, SUM(COALESCE(functional_credit, credit_amount)) as total_credit, COUNT(*) as line_count');

        $query = DB::table('journal_entries as entry')
            ->leftJoinSub($totals, 'totals', 'totals.journal_entry_id', '=', 'entry.id')
            ->leftJoin('users as creator', 'creator.id', '=', 'entry.created_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'entry.approved_by')
            ->leftJoin('users as poster', 'poster.id', '=', 'entry.posted_by')
            ->where('entry.tenant_id', $tenantId)
            ->whereNull('entry.deleted_at')
            ->whereBetween('entry.posting_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('entry.posting_date')
            ->orderBy('entry.journal_number')
            ->orderBy('entry.id')
            ->select([
                'entry.*',
                'totals.total_debit', 'totals.total_credit', 'totals.line_count',
                'creator.name as created_by_name', 'approver.name as approved_by_name', 'poster.name as posted_by_name',
            ]);

        foreach ($query->lazy(500) as $row) {
            yield [
                'journal_entry_id' => (string) $row->id,
                'journal_number' => $row->journal_number !== null ? (int) $row->journal_number : null,
                'entry_number' => $row->entry_number,
                'posting_date' => CarbonImmutable::parse($row->posting_date)->toDateString(),
                'document_date' => $row->document_date !== null ? CarbonImmutable::parse($row->document_date)->toDateString() : null,
                'journal_type' => $row->journal_type,
                'ledger_type' => $row->ledger_type,
                'status' => $row->status,
                'source_document_type' => $row->source_document_type,
                'source_document_id' => $row->source_document_id,
                'document_number' => $row->document_number ?? null,
                'description' => $row->description,
                'total_debit' => bcadd((string) ($row->total_debit ?? '0'), '0', 4),
                'total_credit' => bcadd((string) ($row->total_credit ?? '0'), '0', 4),
                'line_count' => (int) ($row->line_count ?? 0),
                'created_by' => $row->created_by,
                'created_by_name' => $row->created_by_name,
                'created_at' => $this->timestamp($row->created_at),
                'submitted_by' => $row->submitted_by ?? null,
                'submitted_at' => $this->timestamp($row->submitted_at ?? null),
                'approved_by' => $row->approved_by ?? null,
                'approved_by_name' => $row->approved_by_name,
                'approved_at' => $this->timestamp($row->approved_at ?? null),
                'rejected_by' => $row->rejected_by ?? null,
                'rejected_at' => $this->timestamp($row->rejected_at ?? null),
                'rejection_reason' => $row->rejection_reason ?? null,
                'posted_by' => $row->posted_by,
                'posted_by_name' => $row->posted_by_name,
                'posted_at' => $this->timestamp($row->posted_at),
                'reversed_by' => $row->reversed_by,
                'reversed_at' => $this->timestamp($row->reversed_at),
                'reversal_journal_entry_id' => $row->reversal_journal_entry_id,
                'auto_reverse_on' => isset($row->auto_reverse_on) ? CarbonImmutable::parse($row->auto_reverse_on)->toDateString() : null,
            ];
        }
    }

    private function timestamp(mixed $value): ?string
    {
        return $value !== null ? CarbonImmutable::parse((string) $value)->toIso8601String() : null;
    }
}
