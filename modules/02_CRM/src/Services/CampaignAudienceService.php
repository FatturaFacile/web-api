<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\Contact;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\CRM\Models\Campaign;
use Modules\CRM\Models\CampaignMember;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Segment;
use Modules\CRM\Notifications\CampaignMessageNotification;

/**
 * Campaign membership and delivery. Members are contacts or leads, added one by one or from a segment, never twice.
 * Sending respects GDPR: a member is emailed only with a current marketing opt-in for the email channel; everyone
 * else is marked suppressed with the reason, so the campaign report shows who was not contacted and why.
 */
final readonly class CampaignAudienceService
{
    public function __construct(
        private SegmentService $segmentService,
        private ConsentService $consentService,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * Adds a contact or a lead to the campaign; an existing membership is returned unchanged.
     */
    public function addMember(Campaign $campaign, ?string $contactId, ?string $leadId, string $status = 'planned'): CampaignMember
    {
        if (($contactId === null) === ($leadId === null)) {
            throw new InvalidArgumentException('A campaign member is exactly one contact or one lead.');
        }

        $existing = CampaignMember::where('campaign_id', $campaign->id)
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId), fn ($query) => $query->where('lead_id', $leadId))
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return CampaignMember::create([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $campaign->tenant_id,
            'campaign_id' => $campaign->id,
            'contact_id' => $contactId,
            'lead_id' => $leadId,
            'status' => $status,
            'first_associated_at' => now(),
        ]);
    }

    /**
     * Adds every current member of the segment to the campaign.
     *
     * @return array{added: int, already_members: int}
     */
    public function addSegment(Campaign $campaign, Segment $segment): array
    {
        $added = 0;
        $already = 0;

        DB::transaction(function () use ($campaign, $segment, &$added, &$already): void {
            foreach ($this->segmentService->query($segment)->cursor() as $record) {
                $contactId = $record instanceof Contact ? (string) $record->id : null;
                $leadId = $record instanceof Lead ? (string) $record->id : null;
                $member = $this->addMember($campaign, $contactId, $leadId);
                $member->wasRecentlyCreated ? $added++ : $already++;
            }
        });

        return ['added' => $added, 'already_members' => $already];
    }

    /**
     * Emails the members not yet contacted, honouring consent and opt-out.
     *
     * @return array{sent: int, suppressed: int, skipped: int, suppression_reasons: array<string, int>}
     */
    public function send(Campaign $campaign, string $subjectLine, string $body, ?string $actorUserId): array
    {
        $stats = ['sent' => 0, 'suppressed' => 0, 'skipped' => 0, 'suppression_reasons' => []];

        DB::transaction(function () use ($campaign, $subjectLine, $body, &$stats): void {
            $members = CampaignMember::with(['contact', 'lead'])
                ->where('campaign_id', $campaign->id)
                ->lockForUpdate()
                ->get();

            foreach ($members as $member) {
                if ($member->sent_at !== null) {
                    $stats['skipped']++;

                    continue;
                }

                $email = $this->consentService->emailOf($member->contact, $member->lead);
                $consent = $this->consentService->effectiveStatus($member->contact_id, $member->lead_id, 'email', 'marketing');
                $reason = match (true) {
                    $consent === 'withdrawn' => 'opted_out',
                    $consent !== 'granted' => 'no_consent',
                    $email === null => 'no_email',
                    default => null,
                };

                if ($reason !== null) {
                    $member->update(['status' => 'suppressed', 'suppression_reason' => $reason]);
                    $stats['suppressed']++;
                    $stats['suppression_reasons'][$reason] = ($stats['suppression_reasons'][$reason] ?? 0) + 1;

                    continue;
                }

                Notification::route('mail', $email)->notify(new CampaignMessageNotification((string) $campaign->id, $subjectLine, $body));
                $member->update(['status' => 'sent', 'sent_at' => now(), 'suppression_reason' => null]);
                $stats['sent']++;
            }

            if ($campaign->status === 'planned') {
                $campaign->update(['status' => 'active']);
            }
        });

        $this->auditLedger->record(
            action: 'CRM_CAMPAIGN_SENT',
            model: $campaign,
            payloadBefore: null,
            payloadAfter: ['subject' => $subjectLine, ...$stats, 'actor_user_id' => $actorUserId],
            moduleCode: 'MODULE_02_CRM'
        );

        return $stats;
    }
}
