<?php

declare(strict_types=1);

namespace Modules\Communication\Services;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Communication\DTOs\DeliveryMessage;
use Modules\Communication\Models\CampaignRecipient;
use Modules\Communication\Models\CommunicationCampaign;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\Segment;
use Modules\CRM\Services\SegmentService;

/**
 * Campaigns on one channel to the members of a CRM segment. The audience is read from the segment when the campaign
 * starts, every member is checked against the channel opt-out and the CRM consent, and each outcome is written to the
 * send log (recipients). Providers are called outside any transaction.
 */
final readonly class CampaignService
{
    public function __construct(
        private SegmentService $segments,
        private ContactPreferenceService $preferences,
        private MessageDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(string $tenantId, array $data): CommunicationCampaign
    {
        $this->assertSegment($tenantId, $data['segment_id'] ?? null);

        return CommunicationCampaign::create([...$data, 'tenant_id' => $tenantId, 'status' => 'draft']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CommunicationCampaign $campaign, array $data): CommunicationCampaign
    {
        $this->assertEditable($campaign);
        $this->assertSegment((string) $campaign->tenant_id, $data['segment_id'] ?? null);
        $campaign->fill($data)->save();

        return $campaign;
    }

    /**
     * Schedules the campaign: the due-campaign job starts it at or after that time.
     */
    public function schedule(CommunicationCampaign $campaign, string $scheduledAt): CommunicationCampaign
    {
        $this->assertEditable($campaign);
        $this->assertReadyToSend($campaign);
        $campaign->forceFill(['status' => 'scheduled', 'scheduled_at' => $scheduledAt])->save();

        return $campaign;
    }

    public function cancel(CommunicationCampaign $campaign): CommunicationCampaign
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => __('communication_errors.campaign.not_cancellable')]);
        }

        $campaign->forceFill(['status' => 'cancelled'])->save();

        return $campaign;
    }

    public function delete(CommunicationCampaign $campaign): void
    {
        if (! in_array($campaign->status, ['draft', 'scheduled', 'cancelled'], true)) {
            throw ValidationException::withMessages(['status' => __('communication_errors.campaign.locked')]);
        }

        $campaign->delete();
    }

    /**
     * Starts a draft or scheduled campaign now and sends it.
     */
    public function sendNow(CommunicationCampaign $campaign): CommunicationCampaign
    {
        $this->assertEditable($campaign);
        $this->assertReadyToSend($campaign);

        return $this->run($campaign);
    }

    /**
     * Runs the scheduled campaigns of the active tenant that are due.
     *
     * @return int number of campaigns processed
     */
    public function processDue(): int
    {
        $due = CommunicationCampaign::query()->where('status', 'scheduled')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->get();

        foreach ($due as $campaign) {
            $this->run($campaign);
        }

        return $due->count();
    }

    /**
     * Previews the audience the campaign would reach, without sending anything.
     *
     * @return array{total: int, deliverable: int, suppressed: array<string, int>}
     */
    public function preview(CommunicationCampaign $campaign): array
    {
        $segment = $this->segmentOf($campaign);
        $stats = ['total' => 0, 'deliverable' => 0, 'suppressed' => []];

        foreach ($this->segments->query($segment)->cursor() as $record) {
            $stats['total']++;
            $reason = $this->suppressionReason($campaign, $record);

            if ($reason === null) {
                $stats['deliverable']++;
            } else {
                $stats['suppressed'][$reason] = ($stats['suppressed'][$reason] ?? 0) + 1;
            }
        }

        return $stats;
    }

    private function run(CommunicationCampaign $campaign): CommunicationCampaign
    {
        // Atomic claim: only one worker moves the campaign out of draft or scheduled.
        $claimed = CommunicationCampaign::query()
            ->whereKey($campaign->id)
            ->whereIn('status', ['draft', 'scheduled'])
            ->update(['status' => 'sending', 'started_at' => now()]);

        if ($claimed === 0) {
            return $campaign->refresh();
        }

        $campaign->refresh();

        try {
            $this->buildAudience($campaign);
            $this->deliver($campaign);
        } finally {
            $this->finish($campaign);
        }

        return $campaign->refresh();
    }

    private function buildAudience(CommunicationCampaign $campaign): void
    {
        $segment = $this->segmentOf($campaign);

        DB::transaction(function () use ($campaign, $segment): void {
            foreach ($this->segments->query($segment)->cursor() as $record) {
                $reason = $this->suppressionReason($campaign, $record);
                $address = $this->addressOf($campaign->channel, $record);

                CampaignRecipient::create([
                    'tenant_id' => $campaign->tenant_id,
                    'campaign_id' => $campaign->id,
                    'contact_id' => $record instanceof Contact ? (string) $record->id : null,
                    'lead_id' => $record instanceof Lead ? (string) $record->id : null,
                    'address' => $address,
                    'status' => $reason === null ? 'pending' : 'suppressed',
                    'suppression_reason' => $reason,
                ]);
            }
        });
    }

    private function deliver(CommunicationCampaign $campaign): void
    {
        try {
            $settings = $this->dispatcher->settings($campaign->channel);
        } catch (ValidationException $exception) {
            CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')
                ->update(['status' => 'failed', 'error' => 'channel_disabled']);

            return;
        }

        $pending = CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', 'pending')->orderBy('id');

        foreach ($pending->cursor() as $recipient) {
            $record = $this->recordOf($recipient);
            $reason = $record === null ? 'no_address' : $this->suppressionReason($campaign, $record);

            if ($reason !== null) {
                $recipient->forceFill(['status' => 'suppressed', 'suppression_reason' => $reason])->save();

                continue;
            }

            $variables = $this->variablesOf($campaign, $record);
            $log = $this->dispatcher->dispatch(
                $campaign->channel,
                new DeliveryMessage($campaign->channel, (string) $recipient->address, $this->fill((string) ($campaign->subject ?? $campaign->name), $variables), $this->fill($campaign->body, $variables)),
                ['message_type' => 'campaign', 'tenant_id' => $campaign->tenant_id],
                $settings,
            );

            $recipient->forceFill([
                'status' => $log->status === 'failed' ? 'failed' : 'sent',
                'delivery_log_id' => $log->id,
                'error' => $log->status === 'failed' ? $log->error : null,
                'sent_at' => $log->status === 'failed' ? null : now(),
            ])->save();
        }
    }

    private function finish(CommunicationCampaign $campaign): void
    {
        $counts = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $campaign->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'recipients_count' => (int) $counts->sum(),
            'sent_count' => (int) ($counts['sent'] ?? 0),
            'failed_count' => (int) ($counts['failed'] ?? 0),
            'suppressed_count' => (int) ($counts['suppressed'] ?? 0),
        ])->save();
    }

    private function suppressionReason(CommunicationCampaign $campaign, Contact|Lead $record): ?string
    {
        if ($this->addressOf($campaign->channel, $record) === null) {
            return 'no_address';
        }

        return $this->preferences->blockReason(
            (string) $campaign->tenant_id,
            $record instanceof Contact ? (string) $record->id : null,
            $record instanceof Lead ? (string) $record->id : null,
            $campaign->channel,
            $campaign->purpose,
        );
    }

    private function addressOf(string $channel, Contact|Lead $record): ?string
    {
        $value = $channel === 'email' ? $record->email : ($record->phone ?? null);

        return filled($value) ? (string) $value : null;
    }

    private function recordOf(CampaignRecipient $recipient): Contact|Lead|null
    {
        return $recipient->contact_id !== null
            ? Contact::query()->find($recipient->contact_id)
            : Lead::query()->find($recipient->lead_id);
    }

    /**
     * Placeholders a campaign text may use: name, first_name, last_name, company_name, email, phone, campaign_name, date.
     *
     * @return array<string, string>
     */
    private function variablesOf(CommunicationCampaign $campaign, Contact|Lead $record): array
    {
        $firstName = (string) ($record->first_name ?? '');
        $lastName = (string) ($record->last_name ?? '');
        $company = (string) ($record->company_name ?? '');
        $name = (string) ($record->name ?? '');

        if ($name === '') {
            $name = $company !== '' ? $company : trim($firstName.' '.$lastName);
        }

        return [
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'company_name' => $company,
            'email' => (string) ($record->email ?? ''),
            'phone' => (string) ($record->phone ?? ''),
            'campaign_name' => (string) $campaign->name,
            'date' => now()->toDateString(),
        ];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function fill(string $text, array $variables): string
    {
        return preg_replace_callback(
            TemplateRenderer::Placeholder,
            static fn (array $match): string => $variables[$match[1]] ?? $match[0],
            $text
        ) ?? $text;
    }

    private function segmentOf(CommunicationCampaign $campaign): Segment
    {
        return Segment::query()->where('tenant_id', $campaign->tenant_id)->findOrFail($campaign->segment_id);
    }

    private function assertSegment(string $tenantId, mixed $segmentId): void
    {
        if ($segmentId !== null && ! Segment::query()->where('tenant_id', $tenantId)->whereKey($segmentId)->exists()) {
            throw ValidationException::withMessages(['segment_id' => __('communication_errors.campaign.segment_not_found')]);
        }
    }

    private function assertEditable(CommunicationCampaign $campaign): void
    {
        if (! in_array($campaign->status, ['draft', 'scheduled'], true)) {
            throw ValidationException::withMessages(['status' => __('communication_errors.campaign.locked')]);
        }
    }

    private function assertReadyToSend(CommunicationCampaign $campaign): void
    {
        if ($campaign->segment_id === null) {
            throw ValidationException::withMessages(['segment_id' => __('communication_errors.campaign.segment_required')]);
        }
    }
}
