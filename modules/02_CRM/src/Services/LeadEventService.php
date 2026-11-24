<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Modules\CRM\Contracts\LeadEventServiceInterface;
use Modules\CRM\Contracts\LeadScoringServiceInterface;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;

final readonly class LeadEventService implements LeadEventServiceInterface
{
    public function __construct(
        private LeadScoringServiceInterface $scoring,
    ) {}

    public function record(
        Lead $lead,
        string $type,
        ?DateTimeInterface $occurredAt = null,
        array $metadata = [],
        ?string $externalId = null,
    ): array {
        return DB::transaction(function () use ($lead, $type, $occurredAt, $metadata, $externalId): array {
            $lockedLead = Lead::query()->whereKey($lead->id)->lockForUpdate()->firstOrFail();

            if ($externalId !== null && $externalId !== '') {
                $existing = LeadActivityEvent::query()
                    ->where('tenant_id', $lockedLead->tenant_id)
                    ->where('lead_id', $lockedLead->id)
                    ->where('external_id', $externalId)
                    ->first();

                if ($existing instanceof LeadActivityEvent) {
                    return ['event' => $existing, 'created' => false, 'lead' => $lockedLead];
                }
            }

            $occurred = $occurredAt !== null ? CarbonImmutable::instance($occurredAt) : CarbonImmutable::now();

            $event = LeadActivityEvent::create([
                'tenant_id' => $lockedLead->tenant_id,
                'lead_id' => $lockedLead->id,
                'type' => $type,
                'occurred_at' => $occurred,
                'metadata' => $metadata !== [] ? $metadata : null,
                'external_id' => $externalId !== '' ? $externalId : null,
            ]);

            if ($lockedLead->last_activity_at === null || $lockedLead->last_activity_at->lt($occurred)) {
                $lockedLead->forceFill(['last_activity_at' => $occurred])->save();
            }

            $rescored = $this->scoring->rescore($lockedLead);

            return ['event' => $event, 'created' => true, 'lead' => $rescored];
        });
    }
}
