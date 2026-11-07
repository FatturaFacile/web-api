<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Contracts\PdfGenerator;
use App\Services\Tenancy\TenantContext;
use Modules\Associations\Models\Meeting;
use Modules\Associations\Models\MeetingParticipant;
use Modules\Associations\Models\Minutes;

/**
 * Texts and PDFs of the meetings: the notice as plain text for the messaging channels, the printable letters and the
 * minutes.
 */
final class MeetingDocuments
{
    public function __construct(
        private readonly PdfGenerator $pdf,
        private readonly TenantContext $tenantContext,
    ) {}

    public function noticeText(Meeting $meeting): string
    {
        $lines = [
            (string) __('association_governance.notice_intro', ['body' => $meeting->bodyLabel()]),
            $meeting->title,
            (string) __('association_governance.notice_when', ['date' => $meeting->scheduled_at?->format('d/m/Y H:i') ?? '-']),
        ];

        if ($meeting->second_call_at !== null) {
            $lines[] = (string) __('association_governance.notice_second_call', ['date' => $meeting->second_call_at->format('d/m/Y H:i')]);
        }

        if ($meeting->location) {
            $lines[] = (string) __('association_governance.notice_where', ['location' => $meeting->location]);
        }

        if ($meeting->online_url) {
            $lines[] = (string) __('association_governance.notice_online', ['url' => $meeting->online_url]);
        }

        $lines[] = (string) __('association_governance.agenda');

        foreach ($meeting->agendaItems as $item) {
            $lines[] = "{$item->position}. {$item->title}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<string>|null  $participantIds
     */
    public function letters(Meeting $meeting, ?array $participantIds): string
    {
        $participants = MeetingParticipant::query()
            ->with('member.contact')
            ->where('meeting_id', $meeting->id)
            ->when($participantIds !== null, static fn ($query) => $query->whereIn('id', $participantIds))
            ->get();

        return $this->pdf->render('associations::pdf.meeting-letter', [
            'tenant' => $this->tenantContext->getTenant(),
            'meeting' => $meeting->load('agendaItems'),
            'participants' => $participants,
        ]);
    }

    public function minutes(Minutes $minutes): string
    {
        return $this->pdf->render('associations::pdf.minutes', [
            'tenant' => $this->tenantContext->getTenant(),
            'minutes' => $minutes,
            'meeting' => $minutes->meeting,
        ]);
    }
}
