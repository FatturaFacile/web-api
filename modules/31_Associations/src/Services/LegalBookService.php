<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Contracts\PdfGenerator;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Modules\Associations\Enums\MeetingStatus;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\Minutes;
use Modules\Associations\Models\ProtocolEntry;
use Modules\Associations\Models\Reimbursement;
use Modules\Associations\Models\Volunteer;
use Modules\Associations\Support\CsvFile;
use Modules\Associations\Support\Money;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The books of the association, always generated from the live records so they cannot drift from them: the members
 * book with the history of every year, the register of the regular volunteers, the meetings book with the minutes, and
 * the protocol register. Each is available as rows, CSV and PDF; health data never appears.
 */
final class LegalBookService
{
    public const array Books = ['members', 'volunteers', 'meetings', 'protocol'];

    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @return array{book: string, title: string, year: ?int, headers: list<string>, rows: list<list<mixed>>}
     */
    public function book(string $book, ?int $year): array
    {
        [$headers, $rows] = match ($book) {
            'members' => $this->members($year),
            'volunteers' => $this->volunteers($year ?? (int) now()->year),
            'meetings' => $this->meetings($year),
            'protocol' => $this->protocol($year),
            default => throw new NotFoundHttpException('Unknown book.'),
        };

        return [
            'book' => $book,
            'title' => (string) __('association_governance.books.'.$book),
            'year' => $book === 'volunteers' ? ($year ?? (int) now()->year) : $year,
            'headers' => array_map(static fn (string $header): string => (string) __('association_governance.columns.'.$header), $headers),
            'rows' => $rows,
        ];
    }

    public function csv(string $book, ?int $year): string
    {
        $data = $this->book($book, $year);

        return CsvFile::write($data['headers'], $data['rows']);
    }

    public function pdf(string $book, ?int $year): string
    {
        return $this->pdf->render('associations::pdf.book', ['tenant' => $this->tenantContext->getTenant(), 'book' => $this->book($book, $year)]);
    }

    /**
     * Searches meetings, minutes and protocol entries by date, body, participant and text of title, agenda or subject.
     * The text of the minutes is encrypted and is not searched.
     *
     * @param  array{q?: ?string, from?: ?string, to?: ?string, body?: ?string, member_id?: ?string}  $filters
     * @return list<array{type: string, id: string, date: ?string, title: string, reference: ?string}>
     */
    public function search(array $filters): array
    {
        $text = trim((string) ($filters['q'] ?? ''));
        $like = '%'.addcslashes(mb_strtolower($text), '%_\\').'%';
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $meetings = Meeting::query()->with('minutes')
            ->when($from, static fn (Builder $query) => $query->whereDate('scheduled_at', '>=', $from))
            ->when($to, static fn (Builder $query) => $query->whereDate('scheduled_at', '<=', $to))
            ->when($filters['body'] ?? null, static fn (Builder $query, string $body) => $query->where('body', $body))
            ->when($filters['member_id'] ?? null, static fn (Builder $query, string $memberId) => $query->whereHas('participants', static fn (Builder $inner) => $inner->where('member_id', $memberId)))
            ->when($text !== '', static fn (Builder $query) => $query->where(static fn (Builder $inner) => $inner
                ->whereRaw('lower(title) like ?', [$like])
                ->orWhereRaw('lower(coalesce(subject, \'\')) like ?', [$like])
                ->orWhereHas('agendaItems', static fn (Builder $items) => $items->whereRaw('lower(title) like ?', [$like]))))
            ->orderByDesc('scheduled_at')
            ->limit(200)
            ->get();

        $results = [];

        foreach ($meetings as $meeting) {
            $results[] = ['type' => 'meeting', 'id' => $meeting->id, 'date' => $meeting->scheduled_at?->toDateString(), 'title' => $meeting->title, 'reference' => $meeting->body->value];

            if ($meeting->minutes !== null) {
                $results[] = ['type' => 'minutes', 'id' => $meeting->minutes->id, 'date' => $meeting->minutes->approved_at?->toDateString(), 'title' => $meeting->minutes->title, 'reference' => $meeting->minutes->number];
            }
        }

        if (($filters['member_id'] ?? null) === null && ($filters['body'] ?? null) === null) {
            $entries = ProtocolEntry::query()
                ->when($from, static fn (Builder $query) => $query->whereDate('registered_on', '>=', $from))
                ->when($to, static fn (Builder $query) => $query->whereDate('registered_on', '<=', $to))
                ->when($text !== '', static fn (Builder $query) => $query->where(static fn (Builder $inner) => $inner
                    ->whereRaw('lower(subject) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(category, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(number) like ?', [$like])))
                ->orderByDesc('registered_on')
                ->limit(200)
                ->get();

            foreach ($entries as $entry) {
                $results[] = ['type' => 'protocol', 'id' => $entry->id, 'date' => $entry->registered_on?->toDateString(), 'title' => (string) $entry->subject, 'reference' => $entry->number];
            }
        }

        return $results;
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function members(?int $year): array
    {
        $rows = [];
        $memberships = Membership::query()->with(['member.contact', 'year', 'membershipType'])
            ->when($year !== null, static fn (Builder $query) => $query->whereHas('year', static fn (Builder $inner) => $inner
                ->whereDate('starts_on', '<=', CarbonImmutable::create($year)->endOfYear()->toDateString())
                ->whereDate('ends_on', '>=', CarbonImmutable::create($year)->startOfYear()->toDateString())))
            ->get()
            ->sortBy(static fn (Membership $membership): string => mb_strtolower($membership->member->contact->last_name.' '.$membership->member->contact->first_name).($membership->valid_from?->toDateString() ?? ''));

        foreach ($memberships as $membership) {
            $member = $membership->member;
            $rows[] = [
                $membership->card_number,
                $member->contact->last_name,
                $member->contact->first_name,
                $member->tax_code,
                $member->birth_date,
                $member->joined_on?->toDateString(),
                $member->left_on?->toDateString(),
                $membership->year?->code,
                $membership->membershipType?->name,
                $membership->status->value,
                $membership->valid_from?->toDateString(),
                $membership->valid_to?->toDateString(),
                $membership->ceased_on?->toDateString(),
            ];
        }

        return [['card_number', 'last_name', 'first_name', 'tax_code', 'birth_date', 'joined_on', 'left_on', 'year', 'membership_type', 'status', 'valid_from', 'valid_to', 'ceased_on'], array_values($rows)];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function volunteers(int $year): array
    {
        $rows = [];
        $start = CarbonImmutable::create($year)->startOfYear()->toDateString();
        $end = CarbonImmutable::create($year)->endOfYear()->toDateString();

        foreach (Volunteer::query()->with('member.contact')->where('is_occasional', false)->activeInYear($year)->orderBy('started_on')->get() as $volunteer) {
            $reimbursed = Reimbursement::query()->where('member_id', $volunteer->member_id)->where('status', Reimbursement::Paid)
                ->whereDate('paid_on', '>=', $start)->whereDate('paid_on', '<=', $end)->pluck('amount')
                ->reduce(static fn (string $total, mixed $amount): string => Money::add($total, Money::normalize((string) $amount)), '0.00');

            $rows[] = [
                $volunteer->member->contact->last_name,
                $volunteer->member->contact->first_name,
                $volunteer->member->tax_code,
                $volunteer->activity,
                $volunteer->category,
                $volunteer->started_on?->toDateString(),
                $volunteer->ended_on?->toDateString(),
                $volunteer->insurer,
                $volunteer->insurance_policy,
                $volunteer->insurance_expires_on?->toDateString(),
                $reimbursed,
            ];
        }

        return [['last_name', 'first_name', 'tax_code', 'activity', 'category', 'started_on', 'ended_on', 'insurer', 'insurance_policy', 'insurance_expires_on', 'reimbursed'], $rows];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function meetings(?int $year): array
    {
        $rows = [];
        $meetings = Meeting::query()->with(['agendaItems', 'minutes'])
            ->whereIn('status', [MeetingStatus::Convened, MeetingStatus::Held, MeetingStatus::Closed])
            ->when($year !== null, static fn (Builder $query) => $query->whereYear('scheduled_at', $year))
            ->orderBy('scheduled_at')
            ->get();

        foreach ($meetings as $meeting) {
            /** @var ?Minutes $minutes */
            $minutes = $meeting->minutes;
            $rows[] = [
                $meeting->scheduled_at?->format('Y-m-d H:i'),
                $meeting->bodyLabel(),
                $meeting->title,
                $meeting->status->value,
                $meeting->held_on_call,
                $meeting->agendaItems->map(static fn ($item): string => "{$item->position}. {$item->title}")->implode(' | '),
                $minutes?->number,
                $minutes?->approved_at?->toDateString(),
            ];
        }

        return [['date', 'body', 'title', 'status', 'call', 'agenda', 'minutes_number', 'approved_on'], $rows];
    }

    /**
     * @return array{0: list<string>, 1: list<list<mixed>>}
     */
    private function protocol(?int $year): array
    {
        $rows = [];

        foreach (ProtocolEntry::query()->when($year !== null, static fn (Builder $query) => $query->where('fiscal_year', $year))->orderBy('fiscal_year')->orderBy('sequential_number')->get() as $entry) {
            $rows[] = [
                $entry->number,
                $entry->registered_on?->toDateString(),
                $entry->direction,
                $entry->correspondent,
                $entry->subject,
                $entry->category,
                $entry->cancelled_at?->toDateString(),
            ];
        }

        return [['number', 'registered_on', 'direction', 'correspondent', 'subject', 'category', 'cancelled_on'], $rows];
    }
}
