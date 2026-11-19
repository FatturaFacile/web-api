<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\User;
use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Contracts\LeadEventServiceInterface;
use Modules\CRM\Contracts\LeadMergeServiceInterface;
use Modules\CRM\Contracts\LeadScoringServiceInterface;
use Modules\CRM\Events\LeadMergedEvent;
use Modules\CRM\Exceptions\DuplicateResolutionException;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;
use Modules\CRM\Models\LeadDuplicateCandidate;
use Modules\CRM\Notifications\LeadDuplicateDetectedNotification;
use Modules\CRM\Notifications\LeadNotificationPayload;

final readonly class LeadMergeService implements LeadMergeServiceInterface
{
    public function __construct(
        private EventDispatcher $dispatcher,
        private AuditLedgerInterface $auditLedger,
        private LeadScoringServiceInterface $scoring,
        private LeadEventServiceInterface $events,
    ) {}

    public function enrich(Lead $existing, array $attributes, string $matchType, ?string $sourceCode = null, ?string $submissionKey = null): Lead
    {
        $lead = DB::transaction(function () use ($existing, $attributes, $matchType, $sourceCode, $submissionKey): Lead {
            $locked = Lead::query()->whereKey($existing->id)->lockForUpdate()->firstOrFail();
            $before = $locked->toArray();

            $filled = [];
            foreach (self::MERGEABLE_FIELDS as $field) {
                $incoming = $attributes[$field] ?? null;
                if ($this->isBlank($incoming) || ! $this->isBlank($locked->getAttribute($field))) {
                    continue;
                }
                $filled[$field] = $incoming;
            }

            if ($filled !== []) {
                $locked->fill($filled)->save();
            }

            $this->auditLedger->record(
                action: 'LEAD_MERGED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: ['filled_fields' => array_keys($filled), 'match_type' => $matchType, 'source' => $sourceCode],
                moduleCode: 'MODULE_02_CRM'
            );

            // The repeated submission is itself a behavioural signal: log it and re-score.
            $result = $this->events->record($locked, 'form_submitted', now(), array_filter([
                'source' => $sourceCode,
                'merged' => true,
                'match_type' => $matchType,
                'filled_fields' => array_keys($filled),
            ], static fn (mixed $value): bool => $value !== null), $submissionKey);

            $this->publishMerged($result['lead'], (string) $result['lead']->id, $matchType, null);

            return $result['lead'];
        });

        if ($lead->assigned_user_id !== null) {
            User::query()->find($lead->assigned_user_id)?->notify(
                new LeadDuplicateDetectedNotification(LeadNotificationPayload::fromLead($lead), $matchType, merged: true)
            );
        }

        return $lead;
    }

    public function mergeCandidate(LeadDuplicateCandidate $candidate, string $survivor, array $fieldChoices, string $actorUserId): Lead
    {
        return DB::transaction(function () use ($candidate, $survivor, $fieldChoices, $actorUserId): Lead {
            $locked = LeadDuplicateCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw DuplicateResolutionException::alreadyResolved();
            }

            if ($locked->candidate_lead_id === null) {
                throw DuplicateResolutionException::notMergeable('Only lead-to-lead duplicates can be merged; convert the lead with existing_contact_id or existing_account_id instead.');
            }

            $lead = Lead::query()->whereKey($locked->lead_id)->lockForUpdate()->firstOrFail();
            $other = Lead::query()->whereKey($locked->candidate_lead_id)->lockForUpdate()->firstOrFail();

            [$keep, $drop] = $survivor === 'candidate' ? [$other, $lead] : [$lead, $other];
            $keepSide = $survivor === 'candidate' ? 'candidate' : 'lead';

            if ($keep->status === 'converted' && $drop->status === 'converted') {
                throw DuplicateResolutionException::notMergeable('Both leads have already been converted.');
            }

            $before = $keep->toArray();
            $updates = [];
            foreach (self::MERGEABLE_FIELDS as $field) {
                $choice = $fieldChoices[$field] ?? null;
                $keepValue = $keep->getAttribute($field);
                $dropValue = $drop->getAttribute($field);

                if ($choice !== null && $choice !== $keepSide && ! $this->isBlank($dropValue)) {
                    $updates[$field] = $dropValue;
                } elseif ($choice === null && $this->isBlank($keepValue) && ! $this->isBlank($dropValue)) {
                    $updates[$field] = $dropValue;
                }
            }

            $keepTags = is_array($keep->tags) ? $keep->tags : [];
            $dropTags = is_array($drop->tags) ? $drop->tags : [];
            if ($dropTags !== []) {
                $updates['tags'] = array_values(array_unique(array_merge($keepTags, $dropTags), SORT_REGULAR));
            }

            if ($keep->assigned_user_id === null && $drop->assigned_user_id !== null) {
                $updates['assigned_user_id'] = $drop->assigned_user_id;
            }

            if ($updates !== []) {
                $keep->fill($updates)->save();
            }

            $this->moveActivityEvents($drop, $keep);

            $drop->forceFill([
                'merged_into_lead_id' => $keep->id,
                'status' => $drop->status === 'converted' ? 'converted' : 'archived',
            ])->save();

            $locked->forceFill([
                'status' => 'merged',
                'resolved_by' => $actorUserId,
                'resolved_at' => now(),
            ])->save();

            // Other pending candidates pointing at the archived lead are obsolete now.
            LeadDuplicateCandidate::query()
                ->where('tenant_id', $keep->tenant_id)
                ->where('status', 'pending')
                ->whereKeyNot($locked->id)
                ->where(fn ($query) => $query->where('lead_id', $drop->id)->orWhere('candidate_lead_id', $drop->id))
                ->update(['status' => 'dismissed', 'resolved_by' => $actorUserId, 'resolved_at' => now()]);

            $this->auditLedger->record(
                action: 'LEAD_MERGED',
                model: $keep,
                payloadBefore: $before,
                payloadAfter: [
                    'survivor_lead_id' => (string) $keep->id,
                    'merged_lead_id' => (string) $drop->id,
                    'updated_fields' => array_keys($updates),
                    'duplicate_id' => (string) $locked->id,
                ],
                moduleCode: 'MODULE_02_CRM'
            );

            $this->publishMerged($keep, (string) $drop->id, (string) $locked->match_type, $actorUserId);

            return $this->scoring->rescore($keep);
        });
    }

    public function dismissCandidate(LeadDuplicateCandidate $candidate, string $actorUserId): LeadDuplicateCandidate
    {
        return DB::transaction(function () use ($candidate, $actorUserId): LeadDuplicateCandidate {
            $locked = LeadDuplicateCandidate::query()->whereKey($candidate->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== 'pending') {
                throw DuplicateResolutionException::alreadyResolved();
            }

            $before = $locked->toArray();
            $locked->forceFill([
                'status' => 'dismissed',
                'resolved_by' => $actorUserId,
                'resolved_at' => now(),
            ])->save();

            $this->auditLedger->record(
                action: 'LEAD_DUPLICATE_DISMISSED',
                model: $locked,
                payloadBefore: $before,
                payloadAfter: $locked->toArray(),
                moduleCode: 'MODULE_02_CRM'
            );

            return $locked;
        });
    }

    private function moveActivityEvents(Lead $from, Lead $to): void
    {
        $existingExternalIds = LeadActivityEvent::query()
            ->where('lead_id', $to->id)
            ->whereNotNull('external_id')
            ->pluck('external_id')
            ->all();

        LeadActivityEvent::query()
            ->where('lead_id', $from->id)
            ->where(fn ($query) => $query->whereNull('external_id')->orWhereNotIn('external_id', $existingExternalIds === [] ? [''] : $existingExternalIds))
            ->update(['lead_id' => $to->id]);
    }

    private function publishMerged(Lead $survivor, string $mergedLeadId, string $matchType, ?string $actorUserId): void
    {
        $event = new LeadMergedEvent(
            leadId: (string) $survivor->id,
            mergedLeadId: $mergedLeadId,
            matchType: $matchType,
            actorUserId: $actorUserId,
            tenantId: (string) $survivor->tenant_id,
            companyId: (string) $survivor->tenant_id,
        );
        $this->dispatcher->publish($event);
        event($event);
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '') || (is_array($value) && $value === []);
    }
}
