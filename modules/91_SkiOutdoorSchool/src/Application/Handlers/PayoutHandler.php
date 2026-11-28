<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Application\Handlers;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\SkiOutdoorSchool\Application\Commands\GeneratePayoutCommand;
use Modules\SkiOutdoorSchool\Domain\Enums\PayoutStatus;
use Modules\SkiOutdoorSchool\Domain\Exceptions\SkiSchoolException;
use Modules\SkiOutdoorSchool\Domain\Services\PayoutStatementCalculator;
use Modules\SkiOutdoorSchool\Domain\Services\SecretariatCommissionCalculator;
use Modules\SkiOutdoorSchool\Events\PayoutStatementIssued;
use Modules\SkiOutdoorSchool\Models\Instructor;
use Modules\SkiOutdoorSchool\Models\InstructorCommission;
use Modules\SkiOutdoorSchool\Models\PayoutStatement;

/**
 * Monthly payout statements (estratto conto compensi maestro): collects the commissions of the month that are not yet on an issued
 * statement, computes hours, gross fees, secretariat retentions, the withholding of the collaboration regime and the net payable, and
 * moves a statement from draft to issued to paid. A draft is rebuilt each time it is generated; an issued one is never touched.
 */
final class PayoutHandler
{
    public function __construct(private readonly PayoutStatementCalculator $calculator, private readonly SecretariatCommissionCalculator $money) {}

    /**
     * @return array{statements: list<PayoutStatement>, skipped: list<string>}
     *
     * @throws SkiSchoolException instructor_not_found, statement_locked, nothing_to_pay
     */
    public function generate(GeneratePayoutCommand $command): array
    {
        return DB::transaction(function () use ($command): array {
            $commissions = $this->commissionsOfMonth($command->month, $command->instructorId)->groupBy('instructor_id');
            $statements = [];
            $skipped = [];

            if ($command->instructorId !== null) {
                Instructor::query()->find($command->instructorId) ?? throw SkiSchoolException::because('instructor_not_found', [], 404);
            }

            foreach ($commissions as $instructorId => $rows) {
                $instructor = Instructor::query()->findOrFail($instructorId);
                $statement = PayoutStatement::query()->where('instructor_id', $instructorId)->where('period_month', $command->month)->lockForUpdate()->first();

                if ($statement !== null && $statement->status !== PayoutStatus::DRAFT) {
                    if ($command->instructorId !== null) {
                        throw SkiSchoolException::because('statement_locked', ['status' => $statement->status->value]);
                    }

                    $skipped[] = (string) $instructorId;

                    continue;
                }

                $totals = $this->calculator->totals($this->lines($rows), (float) $instructor->withholding_percent);
                $statement ??= new PayoutStatement(['instructor_id' => (string) $instructorId, 'period_month' => $command->month, 'reference' => $this->nextReference($command->month), 'created_by' => $command->userId]);
                $statement->fill([
                    'hours_total' => $totals['hours_total'],
                    'private_hours' => $totals['private_hours'],
                    'group_hours' => $totals['group_hours'],
                    'gross_total' => $this->money->amount($totals['gross_cents']),
                    'retention_total' => $this->money->amount($totals['retention_cents']),
                    'withholding_percent' => (float) $instructor->withholding_percent,
                    'withholding_amount' => $this->money->amount($totals['withholding_cents']),
                    'net_payable' => $this->money->amount($totals['net_cents']),
                    'regime' => $instructor->collaboration_regime->value,
                    'status' => PayoutStatus::DRAFT->value,
                ]);
                $statement->save();
                InstructorCommission::query()->whereIn('id', $rows->pluck('id'))->update(['payout_statement_id' => (string) $statement->id]);
                $statements[] = $statement->refresh();
            }

            if ($statements === [] && $skipped === []) {
                throw SkiSchoolException::because('nothing_to_pay');
            }

            return ['statements' => $statements, 'skipped' => $skipped];
        });
    }

    /**
     * Totals of the month per instructor, without saving anything (the payout dashboard).
     *
     * @return list<array<string, mixed>>
     */
    public function summary(string $month): array
    {
        $rows = $this->commissionsOfMonth($month, null, true)->groupBy('instructor_id');
        $instructors = Instructor::query()->whereIn('id', $rows->keys())->get()->keyBy('id');
        $statements = PayoutStatement::query()->where('period_month', $month)->get()->keyBy('instructor_id');
        $summary = [];

        foreach ($rows as $instructorId => $commissions) {
            $instructor = $instructors->get($instructorId);

            if ($instructor === null) {
                continue;
            }

            $totals = $this->calculator->totals($this->lines($commissions), (float) $instructor->withholding_percent);
            $statement = $statements->get($instructorId);
            $summary[] = [
                'instructor_id' => (string) $instructorId,
                'instructor_name' => $instructor->fullName(),
                'regime' => $instructor->collaboration_regime->value,
                'lines' => $commissions->count(),
                'hours_total' => $totals['hours_total'],
                'private_hours' => $totals['private_hours'],
                'group_hours' => $totals['group_hours'],
                'gross_total' => $this->money->amount($totals['gross_cents']),
                'retention_total' => $this->money->amount($totals['retention_cents']),
                'withholding_percent' => (float) $instructor->withholding_percent,
                'withholding_amount' => $this->money->amount($totals['withholding_cents']),
                'net_payable' => $this->money->amount($totals['net_cents']),
                'statement_id' => $statement?->id,
                'statement_status' => $statement?->status->value,
            ];
        }

        usort($summary, static fn (array $a, array $b): int => strcmp((string) $a['instructor_name'], (string) $b['instructor_name']));

        return $summary;
    }

    /**
     * @throws SkiSchoolException statement_not_found, invalid_statement_transition
     */
    public function issue(string $id): PayoutStatement
    {
        return $this->transition($id, PayoutStatus::DRAFT, PayoutStatus::ISSUED, true);
    }

    /**
     * @throws SkiSchoolException statement_not_found, invalid_statement_transition
     */
    public function markPaid(string $id): PayoutStatement
    {
        return $this->transition($id, PayoutStatus::ISSUED, PayoutStatus::PAID, false);
    }

    /**
     * Throws away a draft: its commissions go back to the pool of the month.
     *
     * @throws SkiSchoolException statement_not_found, invalid_statement_transition
     */
    public function discard(string $id): void
    {
        DB::transaction(function () use ($id): void {
            $statement = PayoutStatement::query()->whereKey($id)->lockForUpdate()->first() ?? throw SkiSchoolException::because('statement_not_found', [], 404);

            if ($statement->status !== PayoutStatus::DRAFT) {
                throw SkiSchoolException::because('invalid_statement_transition', ['from' => $statement->status->value, 'to' => 'DISCARDED']);
            }

            InstructorCommission::query()->where('payout_statement_id', $statement->id)->update(['payout_statement_id' => null]);
            $statement->forceDelete();
        });
    }

    private function transition(string $id, PayoutStatus $from, PayoutStatus $to, bool $stamp): PayoutStatement
    {
        return DB::transaction(function () use ($id, $from, $to, $stamp): PayoutStatement {
            $statement = PayoutStatement::query()->whereKey($id)->lockForUpdate()->first() ?? throw SkiSchoolException::because('statement_not_found', [], 404);

            if ($statement->status !== $from) {
                throw SkiSchoolException::because('invalid_statement_transition', ['from' => $statement->status->value, 'to' => $to->value]);
            }

            $statement->update(['status' => $to->value] + ($stamp ? ['issued_at' => now()] : []));

            if ($to === PayoutStatus::ISSUED) {
                event(new PayoutStatementIssued((string) $statement->id, (string) $statement->instructor_id, $statement->period_month, (float) $statement->net_payable));
            }

            return $statement->refresh();
        });
    }

    /**
     * @return Collection<int, InstructorCommission>
     */
    private function commissionsOfMonth(string $month, ?string $instructorId, bool $includeIssued = false): Collection
    {
        $from = Carbon::parse($month.'-01')->startOfMonth()->toDateString();
        $to = Carbon::parse($month.'-01')->endOfMonth()->toDateString();

        return InstructorCommission::query()
            ->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)
            ->when($instructorId, static fn ($query, string $id) => $query->where('instructor_id', $id))
            ->when(! $includeIssued, static fn ($query) => $query->where(static function ($inner): void {
                $inner->whereNull('payout_statement_id')->orWhereIn('payout_statement_id', PayoutStatement::query()->where('status', PayoutStatus::DRAFT->value)->select('id'));
            }))
            ->orderBy('work_date')
            ->get();
    }

    /**
     * @param  Collection<int, InstructorCommission>|\Illuminate\Support\Collection<int, InstructorCommission>  $rows
     * @return list<array{source_type: string, hours: float, gross_cents: int, retention_cents: int}>
     */
    private function lines(iterable $rows): array
    {
        $lines = [];

        foreach ($rows as $row) {
            $lines[] = [
                'source_type' => $row->source_type->value,
                'hours' => (float) $row->hours,
                'gross_cents' => $this->money->cents((float) $row->gross_fee),
                'retention_cents' => $this->money->cents((float) $row->retention_amount),
            ];
        }

        return $lines;
    }

    private function nextReference(string $month): string
    {
        $count = PayoutStatement::query()->withTrashed()->where('period_month', $month)->count();

        return 'EC-'.str_replace('-', '', $month).'-'.sprintf('%03d', $count + 1);
    }
}
