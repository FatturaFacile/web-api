<?php

declare(strict_types=1);

namespace Modules\PracticeManagement\Services;

use App\Exceptions\BusinessRuleException;
use App\Models\BillableActivity;
use App\Models\Contact;
use App\Models\FeeNote;
use App\Models\Invoice;
use App\Models\LegalCase;
use App\Models\User;
use App\Services\Sales\DocumentNumberingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\PracticeManagement\Models\PeriodClosing;

/**
 * Activity control, fee notes from the logged activities, and the closing of a period across many clients. It builds on
 * the legal cases, billable activities and fee notes of the professionals area.
 */
final class PracticeService
{
    public function __construct(private readonly DocumentNumberingService $numbering) {}

    /**
     * Logged time of a period by professional and case, billed and not, with the unbilled activities older than the
     * threshold flagged.
     *
     * @return array{from: string, to: string, rows: list<array<string, mixed>>, stale_unbilled: int}
     */
    public function activityControl(string $from, string $to, ?string $userId, int $staleDays): array
    {
        $activities = BillableActivity::query()
            ->whereBetween('date', [$from, $to])
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->get();
        $cases = LegalCase::query()->whereIn('id', $activities->pluck('legal_case_id'))->get()->keyBy('id');
        $users = User::query()->whereIn('id', $activities->pluck('user_id'))->pluck('name', 'id');
        $staleBefore = now()->subDays($staleDays)->toDateString();

        $rows = $activities->groupBy(static fn (BillableActivity $activity): string => "{$activity->user_id}|{$activity->legal_case_id}")
            ->map(function (Collection $group) use ($cases, $users): array {
                /** @var BillableActivity $first */
                $first = $group->first();
                $billed = $group->where('is_billed', true);
                $unbilled = $group->where('is_billed', false);

                return [
                    'user_id' => $first->user_id,
                    'user_name' => $users[$first->user_id] ?? null,
                    'legal_case_id' => $first->legal_case_id,
                    'case_number' => $cases[$first->legal_case_id]?->case_number,
                    'client_id' => $cases[$first->legal_case_id]?->client_id,
                    'billed_minutes' => (int) $billed->sum('duration_minutes'),
                    'billed_amount' => $this->sum($billed),
                    'unbilled_minutes' => (int) $unbilled->sum('duration_minutes'),
                    'unbilled_amount' => $this->sum($unbilled),
                ];
            })->sortBy(['user_name', 'case_number'])->values()->all();

        return [
            'from' => $from,
            'to' => $to,
            'rows' => $rows,
            'stale_unbilled' => $activities->where('is_billed', false)->filter(static fn (BillableActivity $activity): bool => $activity->date->toDateString() < $staleBefore)->count(),
        ];
    }

    /**
     * A fee note from unbilled activities of one client. The contribution, withholding and VAT rates come from the
     * request: the module applies no default rate.
     *
     * @param  array<string, mixed>  $data
     */
    public function feeNoteFromActivities(array $data): FeeNote
    {
        return DB::transaction(function () use ($data): FeeNote {
            $activities = BillableActivity::query()->whereIn('id', $data['activity_ids'])->lockForUpdate()->get();
            $caseClients = LegalCase::query()->whereIn('id', $activities->pluck('legal_case_id'))->pluck('client_id', 'id');

            if ($activities->count() !== count(array_unique($data['activity_ids']))) {
                throw new BusinessRuleException('Some activities do not exist.');
            }

            if ($activities->contains('is_billed', true)) {
                throw new BusinessRuleException('Some activities are already billed.');
            }

            if ($activities->contains(static fn (BillableActivity $activity): bool => (string) $caseClients[$activity->legal_case_id] !== (string) $data['client_id'])) {
                throw new BusinessRuleException('Every activity must belong to a case of the client.');
            }

            $taxable = Decimal::round2($this->sum($activities));
            $contribution = Decimal::round2(Decimal::div(Decimal::mul($taxable, (string) $data['cpa_rate']), '100'));
            $vatBase = Decimal::add($taxable, $contribution);
            $vat = Decimal::round2(Decimal::div(Decimal::mul($vatBase, (string) $data['vat_rate']), '100'));
            $withholding = Decimal::round2(Decimal::div(Decimal::mul($taxable, (string) $data['ritenuta_rate']), '100'));
            $cases = $activities->pluck('legal_case_id')->unique();

            $note = FeeNote::create([
                'legal_case_id' => $cases->count() === 1 ? $cases->first() : null,
                'client_id' => $data['client_id'],
                'note_number' => $data['note_number'] ?? $this->numbering->next('fee_note', (int) date('Y', strtotime((string) $data['date'])))['number'],
                'date' => $data['date'],
                'taxable_fees' => $taxable,
                'cassa_previdenza_amount' => $contribution,
                'ritenuta_acconto_amount' => $withholding,
                'vat_amount' => $vat,
                'net_payable' => Decimal::round2(Decimal::sub(Decimal::add($vatBase, $vat), $withholding)),
                'status' => 'draft',
            ]);

            BillableActivity::query()->whereIn('id', $activities->pluck('id'))->update(['is_billed' => true, 'fee_note_id' => $note->id]);

            return $note;
        });
    }

    /**
     * Every client with work, fee notes or a closing in the period: what is left to bill, to issue and to collect.
     *
     * @return list<array<string, mixed>>
     */
    public function closingOverview(string $from, string $to): array
    {
        $activities = BillableActivity::query()->whereBetween('date', [$from, $to])->where('is_billed', false)->get();
        $caseClients = LegalCase::query()->whereIn('id', $activities->pluck('legal_case_id'))->pluck('client_id', 'id');
        $unbilled = $activities->groupBy(static fn (BillableActivity $activity): string => (string) $caseClients[$activity->legal_case_id]);
        $drafts = FeeNote::query()->whereBetween('date', [$from, $to])->where('status', 'draft')->get()->groupBy('client_id');
        $closings = PeriodClosing::query()->whereDate('period_start', $from)->whereDate('period_end', $to)->get()->keyBy('client_id');
        $clientIds = $unbilled->keys()->merge($drafts->keys())->merge($closings->keys())->unique()->values();
        $openInvoices = Invoice::query()->whereIn('contact_id', $clientIds)->whereDate('date', '<=', $to)->where('payment_status', '!=', 'paid')->where('invoice_type', '!=', 'proforma')->get()->groupBy('contact_id');
        $clients = Contact::query()->whereIn('id', $clientIds)->get()->keyBy('id');

        return $clientIds->map(function (string $clientId) use ($unbilled, $drafts, $closings, $openInvoices, $clients): array {
            $closing = $closings->get($clientId);
            $invoices = $openInvoices->get($clientId, collect());

            return [
                'client_id' => $clientId,
                'client_name' => $clients[$clientId]?->company_name ?: trim(($clients[$clientId]?->first_name ?? '').' '.($clients[$clientId]?->last_name ?? '')),
                'unbilled_minutes' => (int) $unbilled->get($clientId, collect())->sum('duration_minutes'),
                'unbilled_amount' => $this->sum($unbilled->get($clientId, collect())),
                'draft_fee_notes' => $drafts->get($clientId, collect())->count(),
                'draft_fee_notes_amount' => Decimal::round2((string) $drafts->get($clientId, collect())->reduce(static fn (string $sum, FeeNote $note): string => Decimal::add($sum, (string) $note->net_payable), '0')),
                'open_invoices' => $invoices->count(),
                'open_invoices_amount' => Decimal::round2((string) $invoices->reduce(static fn (string $sum, Invoice $invoice): string => Decimal::add($sum, Decimal::sub((string) $invoice->total_gross, (string) ($invoice->total_paid ?? '0'))), '0')),
                'closing_id' => $closing?->id,
                'closing_status' => $closing?->status ?? 'not_started',
                'checklist_open' => $closing === null ? null : collect($closing->checklist)->where('done', false)->count(),
            ];
        })->sortBy('client_name')->values()->all();
    }

    /**
     * Opens the closing of a period for a client, once per client and period, with its checklist.
     *
     * @param  array<string, mixed>  $data
     */
    public function openClosing(array $data): PeriodClosing
    {
        return DB::transaction(function () use ($data): PeriodClosing {
            Contact::query()->whereKey($data['client_id'])->lockForUpdate()->firstOrFail();

            if (PeriodClosing::query()->where('client_id', $data['client_id'])->whereDate('period_start', $data['period_start'])->whereDate('period_end', $data['period_end'])->exists()) {
                throw new BusinessRuleException('The client already has a closing for this period.');
            }

            $closing = new PeriodClosing;
            $closing->fill([
                ...$data,
                'status' => 'open',
                'checklist' => array_map(static fn (array $item): array => ['key' => $item['key'], 'label' => $item['label'], 'done' => (bool) ($item['done'] ?? false)], $data['checklist']),
            ]);
            $closing->save();

            return $closing;
        });
    }

    /**
     * Ticks or unticks checks of an open closing; an unknown check key is refused so a typo cannot pass unnoticed.
     *
     * @param  list<array{key: string, done: bool}>  $items
     */
    public function updateChecklist(PeriodClosing $closing, array $items, ?string $notes): PeriodClosing
    {
        return DB::transaction(function () use ($closing, $items, $notes): PeriodClosing {
            $locked = $this->lock($closing);
            $this->assertOpen($locked);
            $done = collect($items)->pluck('done', 'key');
            $known = collect($locked->checklist)->pluck('key');

            if ($done->keys()->diff($known)->isNotEmpty()) {
                throw new BusinessRuleException('Some checks do not belong to the closing.');
            }

            $locked->checklist = array_map(static fn (array $item): array => [...$item, 'done' => (bool) ($done[$item['key']] ?? $item['done'])], $locked->checklist);
            $locked->status = 'in_review';
            $locked->notes = $notes ?? $locked->notes;
            $locked->save();

            return $locked;
        });
    }

    /**
     * Closes the period of a client: every check done and nothing left unbilled, unless the reason is recorded.
     */
    public function close(PeriodClosing $closing, ?string $userId, bool $allowUnbilled, ?string $reason): PeriodClosing
    {
        return DB::transaction(function () use ($closing, $userId, $allowUnbilled, $reason): PeriodClosing {
            $locked = $this->lock($closing);
            $this->assertOpen($locked);

            if (collect($locked->checklist)->contains('done', false)) {
                throw new BusinessRuleException('Some checks of the closing are not done.');
            }

            $caseIds = LegalCase::query()->where('client_id', $locked->client_id)->pluck('id');
            $unbilled = BillableActivity::query()->whereIn('legal_case_id', $caseIds)->where('is_billed', false)
                ->whereBetween('date', [$locked->period_start->toDateString(), $locked->period_end->toDateString()])->count();

            if ($unbilled > 0 && ! $allowUnbilled) {
                throw new BusinessRuleException("{$unbilled} activities of the period are not billed.");
            }

            $locked->update([
                'status' => 'closed',
                'closed_by' => $userId,
                'closed_at' => now(),
                'notes' => $unbilled > 0 ? trim(($locked->notes ?? '')."\nClosed with {$unbilled} unbilled activities: {$reason}") : $locked->notes,
            ]);

            return $locked;
        });
    }

    /**
     * Reopens a closed period for correction; the checklist is kept and the status goes back to in review.
     */
    public function reopen(PeriodClosing $closing): PeriodClosing
    {
        return DB::transaction(function () use ($closing): PeriodClosing {
            $locked = $this->lock($closing);

            if ($locked->status !== 'closed') {
                throw new BusinessRuleException('Only a closed period is reopened.');
            }

            $locked->update(['status' => 'in_review', 'closed_by' => null, 'closed_at' => null]);

            return $locked;
        });
    }

    private function lock(PeriodClosing $closing): PeriodClosing
    {
        return PeriodClosing::query()->lockForUpdate()->findOrFail($closing->id);
    }

    /**
     * @param  Collection<int, BillableActivity>  $activities
     */
    private function sum(Collection $activities): string
    {
        return Decimal::round2((string) $activities->reduce(static fn (string $sum, BillableActivity $activity): string => Decimal::add($sum, (string) $activity->fee_amount), '0'));
    }

    private function assertOpen(PeriodClosing $closing): void
    {
        if ($closing->status === 'closed') {
            throw new BusinessRuleException('The period is closed.');
        }
    }
}
