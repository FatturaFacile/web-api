<?php

declare(strict_types=1);

namespace Modules\CRM\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\CRM\Contracts\LeadDeduplicationServiceInterface;
use Modules\CRM\Contracts\LeadEventServiceInterface;
use Modules\CRM\Contracts\LeadIntakeServiceInterface;
use Modules\CRM\Contracts\LeadMergeServiceInterface;
use Modules\CRM\Contracts\LeadNormalizerInterface;
use Modules\CRM\Contracts\LeadPayloadAdapter;
use Modules\CRM\Contracts\LeadRoutingServiceInterface;
use Modules\CRM\Contracts\LeadServiceInterface;
use Modules\CRM\DTOs\CreateLeadDTO;
use Modules\CRM\DTOs\DuplicateMatch;
use Modules\CRM\DTOs\IntakeResult;
use Modules\CRM\Models\Account;
use Modules\CRM\Models\Lead;
use Modules\CRM\Models\LeadActivityEvent;
use Modules\CRM\Models\LeadDuplicateCandidate;
use Modules\CRM\Models\LeadSetting;
use Modules\CRM\Models\LeadSource;
use Modules\CRM\Notifications\LeadDuplicateDetectedNotification;
use Modules\CRM\Notifications\LeadNotificationPayload;
use Modules\CRM\Services\Intake\LinkedInLeadGenAdapter;
use Modules\CRM\Services\Intake\MetaLeadAdsAdapter;
use Modules\CRM\Services\Intake\WebflowAdapter;
use Modules\CRM\Services\Intake\WebFormAdapter;
use Modules\CRM\Services\Intake\WordPressAdapter;

/**
 * Web-to-lead pipeline: normalize -> dedup -> create/merge -> score -> route -> notify, in one transaction.
 * Notifications are queued with afterCommit, so nothing is delivered for a rolled back delivery.
 * Callers must have verified the delivery and set the source tenant as the active tenant context.
 */
final readonly class LeadIntakeService implements LeadIntakeServiceInterface
{
    public function __construct(
        private Container $container,
        private LeadServiceInterface $leadService,
        private LeadDeduplicationServiceInterface $deduplication,
        private LeadMergeServiceInterface $merger,
        private LeadRoutingServiceInterface $routing,
        private LeadEventServiceInterface $events,
        private LeadNormalizerInterface $normalizer,
    ) {}

    public function adapterFor(LeadSource $source): LeadPayloadAdapter
    {
        $class = match ((string) $source->type) {
            'wordpress' => WordPressAdapter::class,
            'webflow' => WebflowAdapter::class,
            'meta_lead_ads' => MetaLeadAdsAdapter::class,
            'linkedin_lead_gen' => LinkedInLeadGenAdapter::class,
            default => WebFormAdapter::class, // web_form, manual, csv
        };

        /** @var LeadPayloadAdapter $adapter */
        $adapter = $this->container->make($class);

        return $adapter;
    }

    public function verify(Request $request, LeadSource $source): bool
    {
        return $this->adapterFor($source)->verify($request, $source);
    }

    public function ingest(LeadSource $source, array $payload): IntakeResult
    {
        $dto = $this->adapterFor($source)->toDto($payload, $source);
        $this->validate($dto);

        try {
            return DB::transaction(fn (): IntakeResult => $this->process($source, $dto));
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent delivery of the same submission won the race on the external_id unique index.
            $replay = $dto->externalId !== null ? $this->findReplay($source, $dto->externalId) : null;
            if ($replay !== null) {
                return new IntakeResult($replay, IntakeResult::DUPLICATE_IGNORED);
            }

            throw $exception;
        }
    }

    public function ingestEvent(LeadSource $source, array $payload): ?array
    {
        $data = Validator::make($payload, [
            'email' => ['nullable', 'email', 'max:150', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:50', 'required_without:email'],
            'type' => ['required', 'string', Rule::in(LeadActivityEvent::TYPES)],
            'occurred_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'external_id' => ['nullable', 'string', 'max:191'],
        ])->validate();

        $lead = $this->findLeadByIdentity((string) $source->tenant_id, $data['email'] ?? null, $data['phone'] ?? null);
        if ($lead === null) {
            return null;
        }

        $occurredAt = ! empty($data['occurred_at']) ? CarbonImmutable::parse((string) $data['occurred_at']) : null;
        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata['source'] = (string) $source->code;

        $result = $this->events->record($lead, (string) $data['type'], $occurredAt, $metadata, $data['external_id'] ?? null);
        $this->touchSource($source);

        return [
            'lead_id' => (string) $result['lead']->id,
            'event_id' => (string) $result['event']->id,
            'created' => $result['created'],
            'score' => (int) $result['lead']->score,
            'rating' => (string) $result['lead']->rating,
        ];
    }

    private function process(LeadSource $source, CreateLeadDTO $dto): IntakeResult
    {
        $tenantId = (string) $source->tenant_id;
        $this->touchSource($source);

        // 1. Replay of an already processed submission: idempotent answer.
        if ($dto->externalId !== null) {
            $replay = $this->findReplay($source, $dto->externalId);
            if ($replay !== null) {
                return new IntakeResult($replay, IntakeResult::DUPLICATE_IGNORED);
            }
        }

        // 2. Duplicate detection against leads, contacts and accounts.
        $matches = $this->deduplication->findMatches($tenantId, [
            'email' => $dto->email,
            'phone' => $dto->phone,
            'vat_number' => $dto->vatNumber,
            'company_name' => $dto->companyName,
            'country' => $dto->country,
        ]);

        $settings = LeadSetting::forTenant($tenantId);
        $exactLeadMatch = $this->firstExactLeadMatch($matches);

        // 3a. auto_merge: enrich the existing lead instead of creating a new one (exact matches only).
        if ($settings->isAutoMerge() && $exactLeadMatch !== null) {
            $existing = Lead::query()->where('tenant_id', $tenantId)->findOrFail($exactLeadMatch->recordId);
            $merged = $this->merger->enrich(
                $existing,
                $dto->toArray(),
                $exactLeadMatch->matchType,
                (string) $source->code,
                $dto->externalId !== null ? $this->submissionKey($source, $dto->externalId) : null,
            );

            return new IntakeResult((string) $merged->id, IntakeResult::MERGED, (int) $merged->score, (string) $merged->rating, $merged->assigned_user_id);
        }

        // 3b. Create (scored by captureLead), then record duplicate candidates for review.
        $lead = $this->leadService->captureLead($dto);
        $candidates = $this->deduplication->recordCandidates($lead, $matches);

        // 4. Route to a rep (notifies the assignee, or the admins when nobody matched).
        $routing = $this->routing->route($lead);
        $lead->refresh();

        // 5. Tell the owners of the matched records about the possible duplicate.
        $this->notifyDuplicateOwners($lead, $candidates);

        return new IntakeResult(
            leadId: (string) $lead->id,
            status: IntakeResult::CREATED,
            score: (int) $lead->score,
            rating: (string) $lead->rating,
            assignedUserId: $routing->assignedUserId,
        );
    }

    private function validate(CreateLeadDTO $dto): void
    {
        Validator::make($dto->toArray(), [
            'email' => ['nullable', 'email', 'max:150', 'required_without_all:phone,company_name'],
            'phone' => ['nullable', 'string', 'max:50', 'required_without_all:email,company_name'],
            'company_name' => ['nullable', 'string', 'max:200', 'required_without_all:email,phone'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'country' => ['required', 'string', 'size:2'],
            'website' => ['nullable', 'string', 'max:255'],
            'vat_number' => ['nullable', 'string', 'max:30'],
            'employee_count' => ['nullable', 'integer', 'min:0'],
            'annual_revenue_estimate' => ['nullable', 'numeric', 'min:0'],
            'estimated_budget' => ['nullable', 'numeric', 'min:0'],
        ], [
            'required_without_all' => 'At least one of email, phone or company name is required.',
        ])->validate();
    }

    /**
     * @param  list<DuplicateMatch>  $matches
     */
    private function firstExactLeadMatch(array $matches): ?DuplicateMatch
    {
        foreach ($matches as $match) {
            if ($match->recordType === 'lead' && $match->isExact()) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Lead id already produced by this submission: a lead created from it, or a lead it was merged into.
     */
    private function findReplay(LeadSource $source, string $externalId): ?string
    {
        $lead = Lead::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('lead_source_id', $source->id)
            ->where('external_id', $externalId)
            ->first();

        if ($lead instanceof Lead) {
            return (string) ($lead->merged_into_lead_id ?? $lead->id);
        }

        $leadId = LeadActivityEvent::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('external_id', $this->submissionKey($source, $externalId))
            ->value('lead_id');

        return $leadId !== null ? (string) $leadId : null;
    }

    private function submissionKey(LeadSource $source, string $externalId): string
    {
        return mb_substr('intake:'.$source->id.':'.$externalId, 0, 191);
    }

    /**
     * @param  list<LeadDuplicateCandidate>  $candidates
     */
    private function notifyDuplicateOwners(Lead $lead, array $candidates): void
    {
        $payload = LeadNotificationPayload::fromLead($lead);
        $notified = [];

        foreach ($candidates as $candidate) {
            $ownerId = match (true) {
                $candidate->candidate_lead_id !== null => Lead::query()->whereKey($candidate->candidate_lead_id)->value('assigned_user_id'),
                $candidate->account_id !== null => Account::query()->whereKey($candidate->account_id)->value('owner_user_id'),
                default => null,
            };

            if ($ownerId === null || isset($notified[(string) $ownerId])) {
                continue;
            }
            $notified[(string) $ownerId] = true;

            User::query()->find($ownerId)?->notify(
                new LeadDuplicateDetectedNotification($payload, (string) $candidate->match_type, merged: false, duplicateId: (string) $candidate->id)
            );
        }
    }

    private function findLeadByIdentity(string $tenantId, ?string $email, ?string $phone): ?Lead
    {
        $email = $this->normalizer->normalizeEmail($email);
        $phoneE164 = $this->normalizer->normalizePhone($phone, (string) LeadSetting::forTenant($tenantId)->default_country);

        if ($email === null && $phoneE164 === null) {
            return null;
        }

        $lead = Lead::query()
            ->where('tenant_id', $tenantId)
            ->where(function ($query) use ($email, $phoneE164): void {
                if ($email !== null) {
                    $query->orWhere('email', $email);
                }
                if ($phoneE164 !== null) {
                    $query->orWhere('phone_e164', $phoneE164);
                }
            })
            ->orderByRaw('CASE WHEN merged_into_lead_id IS NULL THEN 0 ELSE 1 END')
            ->orderByDesc('created_at')
            ->first();

        if ($lead instanceof Lead && $lead->merged_into_lead_id !== null) {
            $survivor = Lead::query()->where('tenant_id', $tenantId)->find($lead->merged_into_lead_id);

            return $survivor instanceof Lead ? $survivor : $lead;
        }

        return $lead instanceof Lead ? $lead : null;
    }

    private function touchSource(LeadSource $source): void
    {
        $source->forceFill(['last_received_at' => now()])->saveQuietly();
    }
}
