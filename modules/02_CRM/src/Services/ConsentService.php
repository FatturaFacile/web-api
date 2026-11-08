<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\Contact;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\CRM\Events\ConsentRecordedEvent;
use Modules\CRM\Models\Consent;
use Modules\CRM\Models\Lead;

/**
 * GDPR consent records of contacts and leads. Consent is opt-in: marketing is allowed only when the latest record for
 * the subject, channel and purpose is "granted"; no record or a withdrawal means no. Every record is written to the
 * audit ledger and published as a domain event, so the evidence cannot be lost silently.
 */
final readonly class ConsentService
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
    ) {}

    /**
     * Appends a consent record for exactly one subject (contact or lead).
     *
     * @param  array{contact_id?: ?string, lead_id?: ?string, channel: string, purpose: string, status: string, source: string, captured_at?: ?string, ip_address?: ?string, evidence?: ?string}  $data
     */
    public function record(string $tenantId, array $data, ?string $actorUserId): Consent
    {
        $contactId = $data['contact_id'] ?? null;
        $leadId = $data['lead_id'] ?? null;

        if (($contactId === null) === ($leadId === null)) {
            throw new InvalidArgumentException('A consent belongs to exactly one contact or one lead.');
        }

        return DB::transaction(function () use ($tenantId, $data, $contactId, $leadId, $actorUserId): Consent {
            $consent = Consent::create([
                'id' => (string) Str::uuid7(),
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'lead_id' => $leadId,
                'channel' => $data['channel'],
                'purpose' => $data['purpose'],
                'status' => $data['status'],
                'source' => $data['source'],
                'captured_at' => isset($data['captured_at']) ? Carbon::parse($data['captured_at']) : now(),
                'ip_address' => $data['ip_address'] ?? null,
                'evidence' => $data['evidence'] ?? null,
                'recorded_by_user_id' => $actorUserId !== null && Str::isUuid($actorUserId) ? $actorUserId : null,
            ]);

            $this->auditLedger->record(
                action: $consent->status === Consent::Granted ? 'CRM_CONSENT_GRANTED' : 'CRM_CONSENT_WITHDRAWN',
                model: $consent,
                payloadBefore: null,
                payloadAfter: $consent->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            $this->dispatcher->publishAndDispatch(new ConsentRecordedEvent(
                consentId: (string) $consent->id,
                contactId: $contactId,
                leadId: $leadId,
                channel: (string) $consent->channel,
                purpose: (string) $consent->purpose,
                status: (string) $consent->status,
                source: (string) $consent->source,
                tenantId: $tenantId,
                companyId: $tenantId,
            ));

            return $consent;
        });
    }

    /**
     * The effective state (granted, withdrawn) of a subject for a channel and purpose, null when never recorded.
     */
    public function effectiveStatus(?string $contactId, ?string $leadId, string $channel, string $purpose): ?string
    {
        if ($contactId === null && $leadId === null) {
            return null;
        }

        return Consent::query()
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId), fn ($query) => $query->where('lead_id', $leadId))
            ->where('channel', $channel)
            ->where('purpose', $purpose)
            ->orderByDesc('captured_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('status');
    }

    /**
     * True only with an explicit, not withdrawn, opt-in.
     */
    public function allows(?string $contactId, ?string $leadId, string $channel, string $purpose): bool
    {
        return $this->effectiveStatus($contactId, $leadId, $channel, $purpose) === Consent::Granted;
    }

    /**
     * The effective state of every channel and purpose a subject has records for.
     *
     * @return list<array{channel: string, purpose: string, status: string, captured_at: ?string, source: string}>
     */
    public function summary(?string $contactId, ?string $leadId): array
    {
        $records = Consent::query()
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId), fn ($query) => $query->where('lead_id', $leadId))
            ->orderByDesc('captured_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $records
            ->unique(fn (Consent $consent): string => $consent->channel.'|'.$consent->purpose)
            ->map(fn (Consent $consent): array => [
                'channel' => (string) $consent->channel,
                'purpose' => (string) $consent->purpose,
                'status' => (string) $consent->status,
                'captured_at' => $consent->captured_at?->toIso8601String(),
                'source' => (string) $consent->source,
            ])
            ->values()
            ->all();
    }

    /**
     * Copies the effective consents of a converted lead to its contact, keeping the original capture time, so that
     * the person's choices survive the conversion. A contact that already has a record for a channel and purpose keeps
     * its own (it is newer information about the same person).
     */
    public function carryOverFromLead(Lead $lead, string $contactId, string $actorUserId): void
    {
        foreach ($this->summary(null, (string) $lead->id) as $state) {
            if ($this->effectiveStatus($contactId, null, $state['channel'], $state['purpose']) !== null) {
                continue;
            }

            $this->record((string) $lead->tenant_id, [
                'contact_id' => $contactId,
                'channel' => $state['channel'],
                'purpose' => $state['purpose'],
                'status' => $state['status'],
                'source' => 'lead_conversion',
                'captured_at' => $state['captured_at'],
                'evidence' => "Carried over from lead {$lead->id}",
            ], $actorUserId);
        }
    }

    /**
     * The address a marketing message to the subject would go to.
     */
    public function emailOf(?Contact $contact, ?Lead $lead): ?string
    {
        $email = $contact->email ?? $lead->email ?? null;

        return filled($email) ? (string) $email : null;
    }
}
