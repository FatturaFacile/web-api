<?php

declare(strict_types=1);

namespace Modules\GeneralLedger\Services;

use Carbon\CarbonImmutable;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\GeneralLedger\Actions\OpenFiscalYearAction;
use Modules\GeneralLedger\Contracts\PeriodClosingServiceInterface;
use Modules\GeneralLedger\Enums\LedgerBook;
use Modules\GeneralLedger\Exceptions\LedgerConflictException;
use Modules\GeneralLedger\Models\FiscalPeriod;
use Modules\GeneralLedger\Models\FiscalYear;
use Modules\GeneralLedger\Models\JournalEntry;
use Modules\GeneralLedger\Models\YearEndClosingStage;

/**
 * The 4-stage year-end workflow of a fiscal year in one ledger book, each stage recorded once and run in order:
 *
 *  1. period lock       - every open period of the year is closed, so nothing can be backdated while closing;
 *  2. net result        - the profit or loss of the income statement of the book is computed and recorded;
 *  3. closing           - revenue and expense accounts are closed against retained earnings (for the statutory
 *                         book this is the fiscal year closing, which marks the year closed);
 *  4. opening           - the balance sheet balances are carried forward as the opening entry of the next year.
 *
 * A stage already completed is not run again: asking for it returns the recorded result, so a repeated request or a
 * re-run is safe and never posts a second entry.
 */
final readonly class YearEndClosingWorkflowService
{
    /**
     * @var array<int, string>
     */
    public const array Stages = [1 => 'period_lock', 2 => 'net_result', 3 => 'closing', 4 => 'opening'];

    public function __construct(
        private PeriodClosingServiceInterface $closing,
        private PeriodClosingLedgerBooks $books,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * @return array{fiscal_year_id: string, year_code: string, year_status: string, ledger: string, ledger_type: string, stages: list<array<string, mixed>>, next_stage: ?int, is_complete: bool}
     */
    public function status(FiscalYear $fy, LedgerBook $book): array
    {
        $done = YearEndClosingStage::where('fiscal_year_id', $fy->id)->where('ledger_type', $book->ledgerType())->get()->keyBy('stage');
        $stages = [];
        $next = null;

        foreach (self::Stages as $number => $key) {
            $record = $done->get($number);
            $isNext = $record === null && $next === null;
            $next ??= $record === null ? $number : null;

            $stages[] = [
                'stage' => $number,
                'key' => $key,
                'status' => $record !== null ? 'completed' : ($isNext ? 'ready' : 'blocked'),
                'journal_entry_id' => $record?->journal_entry_id,
                'summary' => $record?->summary,
                'executed_by' => $record?->executed_by,
                'executed_at' => $record?->executed_at->toIso8601String(),
            ];
        }

        return [
            'fiscal_year_id' => (string) $fy->id,
            'year_code' => (string) $fy->year_code,
            'year_status' => (string) $fy->status,
            'ledger' => $book->value,
            'ledger_type' => $book->ledgerType(),
            'stages' => $stages,
            'next_stage' => $next,
            'is_complete' => $next === null,
        ];
    }

    /**
     * Runs a stage, or returns it when already completed.
     *
     * @return array{already_completed: bool, stage: array<string, mixed>}
     *
     * @throws LedgerConflictException when an earlier stage is not completed yet
     */
    public function execute(string $fiscalYearId, LedgerBook $book, int $stage, string $actorId): array
    {
        $actor = Str::isUuid($actorId) ? $actorId : (string) Str::uuid7();

        return DB::transaction(function () use ($fiscalYearId, $book, $stage, $actor): array {
            /** @var FiscalYear $fy */
            $fy = FiscalYear::where('id', $fiscalYearId)->lockForUpdate()->firstOrFail();
            $ledgerType = $book->ledgerType();

            $completed = YearEndClosingStage::where('fiscal_year_id', $fy->id)->where('ledger_type', $ledgerType)->pluck('stage')->map(static fn ($value): int => (int) $value)->all();

            if (in_array($stage, $completed, true)) {
                return ['already_completed' => true, 'stage' => $this->stageOf($fy, $book, $stage)];
            }

            for ($previous = 1; $previous < $stage; $previous++) {
                if (! in_array($previous, $completed, true)) {
                    throw new LedgerConflictException("Stage {$stage} of the closing of fiscal year [{$fy->year_code}] cannot run before stage {$previous} is completed.");
                }
            }

            [$entry, $summary] = match ($stage) {
                1 => [null, $this->lockPeriods($fy, $actor)],
                2 => [null, $this->netResult($fy, $ledgerType)],
                3 => $this->close($fy, $ledgerType, $actor),
                4 => $this->open($fy, $ledgerType, $actor),
                default => throw new LedgerConflictException("Unknown closing stage {$stage}."),
            };

            YearEndClosingStage::create([
                'tenant_id' => $fy->tenant_id,
                'fiscal_year_id' => $fy->id,
                'ledger_type' => $ledgerType,
                'stage' => $stage,
                'stage_key' => self::Stages[$stage],
                'journal_entry_id' => $entry?->id,
                'summary' => $summary,
                'executed_by' => $actor,
                'executed_at' => now(),
            ]);

            $this->auditLedger->record(
                action: 'year_end_closing_stage_completed',
                model: $fy,
                payloadBefore: null,
                payloadAfter: ['ledger' => $book->value, 'stage' => $stage, 'key' => self::Stages[$stage], 'summary' => $summary],
                moduleCode: 'MODULE_22_GENERAL_LEDGER'
            );

            return ['already_completed' => false, 'stage' => $this->stageOf($fy->refresh(), $book, $stage)];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function stageOf(FiscalYear $fy, LedgerBook $book, int $stage): array
    {
        return $this->status($fy, $book)['stages'][$stage - 1];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockPeriods(FiscalYear $fy, string $actor): array
    {
        $closed = 0;

        foreach (FiscalPeriod::where('fiscal_year_id', $fy->id)->where('status', '!=', 'closed')->orderBy('period_number')->get() as $period) {
            $this->closing->closePeriod((string) $period->id, $actor);
            $closed++;
        }

        return ['periods_closed' => $closed, 'periods_total' => FiscalPeriod::where('fiscal_year_id', $fy->id)->count()];
    }

    /**
     * @return array<string, mixed>
     */
    private function netResult(FiscalYear $fy, string $ledgerType): array
    {
        $net = $this->books->netResult($fy, $ledgerType);

        return ['net_result' => $net, 'result' => bccomp($net, '0', 4) >= 0 ? 'profit' : 'loss'];
    }

    /**
     * @return array{0: ?JournalEntry, 1: array<string, mixed>}
     */
    private function close(FiscalYear $fy, string $ledgerType, string $actor): array
    {
        if ($ledgerType === 'statutory') {
            if ($fy->status !== 'closed') {
                $this->closing->closeFiscalYear((string) $fy->id, $actor);
            }

            $entry = JournalEntry::where('tenant_id', $fy->tenant_id)->where('ledger_type', 'statutory')
                ->where('source_document_type', PeriodClosingService::ClosingBalanceSource)->where('source_document_id', $fy->id)->where('status', 'posted')->first();
        } else {
            $entry = $this->books->close($fy, $ledgerType, $actor);
        }

        return [$entry, ['net_result' => $this->books->netResult($fy, $ledgerType), 'entry_number' => $entry?->entry_number, 'lines' => $entry?->lines()->count() ?? 0]];
    }

    /**
     * @return array{0: ?JournalEntry, 1: array<string, mixed>}
     */
    private function open(FiscalYear $fy, string $ledgerType, string $actor): array
    {
        $entry = $ledgerType === 'statutory'
            ? $this->closing->carryForwardOpeningBalances((string) $fy->id, $actor)
            : $this->books->open($fy, $this->nextYear($fy), $ledgerType, $actor);

        return [$entry, ['entry_number' => $entry?->entry_number, 'opening_date' => $entry !== null ? CarbonImmutable::parse($entry->posting_date)->toDateString() : null, 'lines' => $entry?->lines()->count() ?? 0]];
    }

    private function nextYear(FiscalYear $fy): ?FiscalYear
    {
        $end = CarbonImmutable::parse($fy->end_date);
        $next = FiscalYear::where('tenant_id', $fy->tenant_id)->whereDate('start_date', $end->addDay()->toDateString())->first();

        if ($next !== null) {
            return $next;
        }

        $isCalendarYear = CarbonImmutable::parse($fy->start_date)->dayOfYear === 1 && $end->isSameDay($end->endOfYear());

        return $isCalendarYear ? (new OpenFiscalYearAction)->execute((string) $fy->tenant_id, $end->year + 1) : null;
    }
}
