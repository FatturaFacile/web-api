<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Services\Helpdesk;

use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\ServiceManagement\Models\CSATSurvey;
use Modules\ServiceManagement\Models\HelpdeskSetting;
use Modules\ServiceManagement\Models\Ticket;
use Modules\ServiceManagement\Services\Helpdesk\Email\HelpdeskMailer;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Satisfaction survey of a resolved ticket: one per ticket, sent to the requester with a secret link. The link carries
 * the tenant: the survey is found by the hash of its token, never by anything the client names.
 */
final readonly class CsatSurveyService
{
    public function __construct(
        private HelpdeskMailer $mailer,
        private TicketActivityLog $activityLog,
        private TenantContext $tenantContext,
    ) {}

    /**
     * Creates and sends the survey of a resolved ticket, unless surveys are off, the ticket has no requester address or
     * already has a survey.
     *
     * @return string|null the plain token of the link, null when no survey was sent
     */
    public function sendFor(Ticket $ticket): ?string
    {
        if (! HelpdeskSetting::current($ticket->tenant_id)->csat_enabled || $ticket->requesterEmail() === null || $ticket->merged_into_ticket_id !== null) {
            return null;
        }

        $token = bin2hex(random_bytes(32));

        $created = DB::transaction(function () use ($ticket, $token): bool {
            Ticket::query()->whereKey($ticket->id)->lockForUpdate()->first();

            if (CSATSurvey::query()->where('ticket_id', $ticket->id)->exists()) {
                return false;
            }

            CSATSurvey::query()->create([
                'tenant_id' => $ticket->tenant_id,
                'company_id' => $ticket->company_id,
                'ticket_id' => $ticket->id,
                'customer_id' => $ticket->customer_id,
                'respondent_email' => $ticket->requesterEmail(),
                'score' => null,
                'status' => CSATSurvey::StatusPending,
                'token_hash' => hash('sha256', $token),
                'sent_at' => now(),
                'expires_at' => now()->addDays((int) config('helpdesk.csat_link_days', 30)),
            ]);

            $this->activityLog->record($ticket, 'csat_sent');

            return true;
        });

        if (! $created) {
            return null;
        }

        $link = url("/api/v1/portal/csat/{$token}");
        $this->mailer->sendNotice($ticket, "Your request {$ticket->ticket_number} has been resolved.\n\nHow satisfied are you with our help? Rate us from 1 to 5 here:\n{$link}");

        return $token;
    }

    /**
     * The survey of a plain token, with the tenant context set to the survey's tenant.
     */
    public function resolve(string $token): CSATSurvey
    {
        $survey = CSATSurvey::query()->withoutGlobalScopes()->with('ticket')->where('token_hash', hash('sha256', trim($token)))->first()
            ?? throw new NotFoundHttpException('Invalid survey link.');

        $this->tenantContext->setTenant($survey->tenant()->first());

        return $survey;
    }
}
