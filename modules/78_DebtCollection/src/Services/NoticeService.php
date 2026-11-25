<?php

declare(strict_types=1);

namespace Modules\DebtCollection\Services;

use App\Models\Contact;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\DebtCollection\Exceptions\DebtCollectionException;
use Modules\DebtCollection\Models\DunningCase;
use Modules\DebtCollection\Models\Notice;
use Modules\DebtCollection\Models\NoticeLine;
use Modules\DebtCollection\Models\Policy;
use Modules\DebtCollection\Models\PolicyLevel;
use Modules\GeneralLedger\Services\Tax\Decimal;
use Modules\Invoicing\Models\ReceivableItem;

/**
 * Creates the dunning notices of the proposal and the Italian formal notice (messa in mora), moves the dunning case of
 * every item up one level, optionally posts interest and fees to the customer ledger as a charge item, and cancels a
 * notice that was not sent.
 */
final class NoticeService
{
    public const string KindReminder = 'reminder';

    public const string KindMessaInMora = 'messa_in_mora';

    /** Legal reference printed on the notice, by jurisdiction. */
    private const array LegalBasis = ['CH' => 'Art. 104 CO', 'IT' => 'D.Lgs. 231/2002'];

    public function __construct(
        private readonly ProposalService $proposals,
        private readonly TemplateService $templates,
        private readonly HistoryRecorder $history,
        private readonly DefaultPolicyProvisioner $defaults,
    ) {}

    /**
     * @param  array{customer_id: string, currency: string, level: int, receivable_item_ids: list<string>}  $group
     */
    public function generate(string $tenantId, array $group, CarbonInterface $asOf, string $channel, ?string $locale, bool $postCharges, ?string $actorId): Notice
    {
        $asOf = Carbon::instance($asOf)->startOfDay();
        $proposal = $this->proposals->build($tenantId, ['customer_id' => $group['customer_id'], 'currency' => $group['currency'], 'item_ids' => $group['receivable_item_ids']], $asOf);

        $rows = array_values(array_filter(
            $proposal['items'],
            static fn (array $row): bool => $row['status'] === ProposalService::Proposed && $row['proposed_level'] === (int) $group['level'],
        ));

        if (count($rows) !== count(array_unique($group['receivable_item_ids']))) {
            throw new DebtCollectionException('One or more items are not due for this dunning level.', 'item_not_proposable');
        }

        $policy = Policy::query()->with('levels')->findOrFail($rows[0]['policy_id']);
        $level = $policy->levels->firstWhere('level', (int) $group['level']);

        if (! $level instanceof PolicyLevel) {
            throw new DebtCollectionException('The dunning level is not configured.', 'invalid_level');
        }

        $contact = Contact::query()->findOrFail($group['customer_id']);
        $jurisdiction = (string) $rows[0]['jurisdiction'];

        return $this->persist(
            $tenantId,
            self::KindReminder,
            $contact,
            $jurisdiction,
            (string) $group['currency'],
            (int) $group['level'],
            array_map(static fn (array $row): array => [
                'receivable_item_id' => $row['receivable_item_id'],
                'document_number' => $row['document_number'],
                'due_date' => $row['due_date'],
                'principal' => $row['open_amount'],
                'interest' => $row['interest_amount'],
                'interest_days' => $row['interest_days'],
                'rate_percent' => $row['interest_rate_percent'],
            ], $rows),
            Decimal::round2((string) $level->fee_amount),
            $asOf,
            $asOf->copy()->addDays($level->deadline_days),
            $channel,
            $this->localeFor($contact, $locale),
            'level_'.$level->level,
            $postCharges,
            $actorId,
        );
    }

    /**
     * The formal notice of default of Italian law (messa in mora): every given open item of the customer with the
     * D.Lgs. 231/2002 interest, the fee of the final level and a deadline, whatever level the items had reached.
     *
     * @param  list<string>  $itemIds
     */
    public function messaInMora(string $tenantId, string $customerId, array $itemIds, CarbonInterface $asOf, string $channel, ?string $locale, bool $postCharges, ?string $actorId): Notice
    {
        $asOf = Carbon::instance($asOf)->startOfDay();
        $contact = Contact::query()->findOrFail($customerId);
        $tenantCountry = (string) (Tenant::query()->whereKey($tenantId)->value('country') ?? 'IT');
        $jurisdiction = $this->proposals->jurisdictionOf($contact, $tenantCountry);

        if ($jurisdiction !== 'IT') {
            throw new DebtCollectionException('The messa in mora applies to Italian debtors.', 'wrong_jurisdiction');
        }

        $items = ReceivableItem::query()->whereIn('id', $itemIds)->where('customer_id', $customerId)->where('open_amount', '>', 0)->where('due_date', '<', $asOf->toDateString())->get();

        if ($items->isEmpty() || $items->count() !== count(array_unique($itemIds))) {
            throw new DebtCollectionException('Select overdue open items of the customer.', 'item_not_overdue');
        }

        if ($items->pluck('currency')->unique()->count() !== 1) {
            throw new DebtCollectionException('The items must share one currency.', 'mixed_currency');
        }

        $currency = (string) $items->first()->currency;
        $this->defaults->ensure();
        $policy = Policy::query()->with('levels')->where('is_active', true)->where('jurisdiction', 'IT')->orderByDesc('is_default')->first()
            ?? throw new DebtCollectionException('No active Italian dunning policy.', 'no_policy');
        $final = $policy->levels->where('is_active', true)->sortByDesc('level')->first();

        if (! $final instanceof PolicyLevel) {
            throw new DebtCollectionException('The dunning level is not configured.', 'invalid_level');
        }

        $posted = $this->proposals->postedInterest($items->pluck('id')->all());
        $lines = [];

        foreach ($items as $item) {
            $priced = $this->proposals->priceInterest($tenantId, $item, $policy, 'IT', $asOf, $posted[(string) $item->id] ?? '0');
            $lines[] = [
                'receivable_item_id' => (string) $item->id,
                'document_number' => (string) $item->document_number,
                'due_date' => $item->due_date->toDateString(),
                'principal' => Decimal::round2((string) $item->open_amount),
                'interest' => $priced['interest'],
                'interest_days' => $priced['days'],
                'rate_percent' => $priced['rate_percent'],
            ];
        }

        return $this->persist(
            $tenantId,
            self::KindMessaInMora,
            $contact,
            'IT',
            $currency,
            3,
            $lines,
            Decimal::round2((string) $final->fee_amount),
            $asOf,
            $asOf->copy()->addDays(max(1, $final->deadline_days)),
            $channel,
            $this->localeFor($contact, $locale),
            'messa_in_mora',
            $postCharges,
            $actorId,
        );
    }

    /**
     * Cancels a notice that has not been sent and takes its items back to the level they had before.
     */
    public function cancel(Notice $notice, ?string $actorId): Notice
    {
        if (! in_array($notice->status, ['draft', 'queued', 'failed'], true)) {
            throw new DebtCollectionException('A notice that was sent cannot be cancelled.', 'notice_not_cancellable');
        }

        return DB::transaction(function () use ($notice, $actorId): Notice {
            $notice->update(['status' => 'cancelled']);

            foreach ($notice->lines as $line) {
                $this->rewindCase((string) $line->receivable_item_id, (string) $notice->id);
            }

            if ($notice->charge_receivable_item_id !== null) {
                ReceivableItem::query()->whereKey($notice->charge_receivable_item_id)->update(['status' => ReceivableItem::StatusWrittenOff, 'open_amount' => '0.00']);
            }

            $this->history->record((string) $notice->customer_id, 'notice_cancelled', 'notice', (string) $notice->id, (int) $notice->level, (string) $notice->total, (string) $notice->currency, ['number' => $notice->number], $actorId);

            return $notice->refresh();
        });
    }

    /**
     * @param  list<array{receivable_item_id: string, document_number: string, due_date: string, principal: string, interest: string, interest_days: int, rate_percent: string}>  $lines
     */
    private function persist(string $tenantId, string $kind, Contact $contact, string $jurisdiction, string $currency, int $level, array $lines, string $fee, Carbon $asOf, Carbon $newDueDate, string $channel, string $locale, string $templateCode, bool $postCharges, ?string $actorId): Notice
    {
        $principal = '0';
        $interest = '0';

        foreach ($lines as $line) {
            $principal = Decimal::add($principal, $line['principal']);
            $interest = Decimal::add($interest, $line['interest']);
        }

        $principal = Decimal::round2($principal);
        $interest = Decimal::round2($interest);
        $total = Decimal::round2(Decimal::add(Decimal::add($principal, $interest), $fee));

        return DB::transaction(function () use ($tenantId, $kind, $contact, $jurisdiction, $currency, $level, $lines, $fee, $asOf, $newDueDate, $channel, $locale, $templateCode, $postCharges, $actorId, $principal, $interest, $total): Notice {
            $number = $this->nextNumber($tenantId, $asOf);
            $rendered = $this->templates->render($templateCode, $locale, $jurisdiction, $this->placeholders($contact, $number, $asOf, $newDueDate, $currency, $principal, $interest, $fee, $total, $jurisdiction, $lines, $tenantId));

            $notice = Notice::create([
                'number' => $number,
                'kind' => $kind,
                'level' => $level,
                'customer_id' => $contact->id,
                'jurisdiction' => $jurisdiction,
                'currency' => $currency,
                'principal' => $principal,
                'interest' => $interest,
                'fee' => $fee,
                'total' => $total,
                'issued_on' => $asOf->toDateString(),
                'new_due_date' => $newDueDate->toDateString(),
                'channel' => $channel,
                'status' => 'draft',
                'locale' => $locale,
                'subject' => $rendered['subject'],
                'body' => $rendered['body'],
                'created_by' => $actorId,
            ]);

            foreach ($lines as $line) {
                NoticeLine::create([...$line, 'notice_id' => $notice->id]);
                $this->advanceCase($contact, $jurisdiction, $currency, $line['receivable_item_id'], $level, $asOf, $newDueDate, $kind);
            }

            if ($postCharges && Decimal::isPositive(Decimal::add($interest, $fee))) {
                $charge = ReceivableItem::create([
                    'customer_id' => $contact->id,
                    'invoice_id' => null,
                    'sequence' => 1,
                    'document_type' => ProposalService::ChargeDocumentType,
                    'document_number' => $number,
                    'currency' => $currency,
                    'original_amount' => Decimal::round2(Decimal::add($interest, $fee)),
                    'open_amount' => Decimal::round2(Decimal::add($interest, $fee)),
                    'issue_date' => $asOf->toDateString(),
                    'due_date' => $newDueDate->toDateString(),
                    'status' => ReceivableItem::StatusOpen,
                ]);
                $notice->update(['charge_receivable_item_id' => $charge->id]);
            }

            $this->history->record((string) $contact->id, 'notice_generated', 'notice', (string) $notice->id, $level, $total, $currency, ['number' => $number, 'kind' => $kind, 'items' => count($lines)], $actorId);

            return $notice->load(['lines', 'receipts']);
        });
    }

    private function advanceCase(Contact $contact, string $jurisdiction, string $currency, string $itemId, int $level, Carbon $asOf, Carbon $deadline, string $kind): void
    {
        $case = DunningCase::query()->where('receivable_item_id', $itemId)->first() ?? new DunningCase(['receivable_item_id' => $itemId, 'customer_id' => $contact->id, 'jurisdiction' => $jurisdiction, 'currency' => $currency]);
        $case->fill([
            'current_level' => max((int) ($case->current_level ?? 0), $level),
            'last_notice_on' => $asOf->toDateString(),
            'deadline_on' => $deadline->toDateString(),
            'status' => $kind === self::KindMessaInMora ? 'enforcement' : 'open',
        ])->save();
    }

    private function rewindCase(string $itemId, string $cancelledNoticeId): void
    {
        $case = DunningCase::query()->where('receivable_item_id', $itemId)->first();

        if ($case === null) {
            return;
        }

        $previous = NoticeLine::query()
            ->where('receivable_item_id', $itemId)
            ->whereHas('notice', static fn ($query) => $query->where('status', '!=', 'cancelled')->where('id', '!=', $cancelledNoticeId))
            ->with('notice')
            ->get()
            ->sortByDesc(static fn (NoticeLine $line): string => (string) $line->notice?->issued_on?->toDateString().$line->notice?->level)
            ->first();

        $case->update([
            'current_level' => $previous?->notice?->level,
            'last_notice_on' => $previous?->notice?->issued_on?->toDateString(),
            'deadline_on' => $previous?->notice?->new_due_date?->toDateString(),
            'status' => 'open',
        ]);
    }

    private function nextNumber(string $tenantId, Carbon $asOf): string
    {
        $prefix = 'DC-'.$asOf->format('Y').'-';
        $last = Notice::withTrashed()->where('number', 'like', $prefix.'%')->orderByDesc('number')->value('number');
        $sequence = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    private function localeFor(Contact $contact, ?string $requested): string
    {
        foreach ([$requested, (string) $contact->document_language] as $candidate) {
            if (in_array($candidate, TemplateService::Locales, true)) {
                return (string) $candidate;
            }
        }

        return 'it';
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, string>
     */
    private function placeholders(Contact $contact, string $number, Carbon $asOf, Carbon $newDueDate, string $currency, string $principal, string $interest, string $fee, string $total, string $jurisdiction, array $lines, string $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);
        $itemLines = array_map(static fn (array $line): string => $line['document_number'].' - '.Carbon::parse((string) $line['due_date'])->format('d.m.Y').' - '.$currency.' '.$line['principal'], $lines);
        $rate = $lines === [] ? '0' : rtrim(rtrim((string) $lines[0]['rate_percent'], '0'), '.');

        return [
            'customer_name' => $this->proposals->nameOf($contact),
            'company_name' => (string) ($tenant?->legal_name ?: $tenant?->name),
            'notice_number' => $number,
            'issued_on' => $asOf->format('d.m.Y'),
            'new_due_date' => $newDueDate->format('d.m.Y'),
            'currency' => $currency,
            'principal' => $principal,
            'interest' => $interest,
            'fee' => $fee,
            'total' => $total,
            'interest_rate' => $rate,
            'legal_basis' => self::LegalBasis[$jurisdiction] ?? '',
            'items' => implode("\n", $itemLines),
        ];
    }
}
