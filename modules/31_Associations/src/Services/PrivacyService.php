<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Contracts\PdfGenerator;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\Consent;
use Modules\Associations\Models\DataSubjectRequest;
use Modules\Associations\Models\FeeAssignment;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\PrivacySubject;
use Modules\Associations\Models\ProcessingActivity;
use Modules\Associations\Models\Receipt;
use Modules\Associations\Support\CsvFile;

/**
 * The data protection records: processing activities in versions, the register of Article 30 of Regulation (EU)
 * 2016/679, privacy notices composed from the data of the company, consents with proof, and the requests of the data
 * subjects with their deadlines. Generated texts are templates: a professional has to validate them before use.
 */
final class PrivacyService
{
    /**
     * Meta returned with every generated text.
     */
    public const array TemplateMeta = [
        'template' => true,
        'requires_professional_validation' => true,
        'notice' => 'Generated from the data of the company as a starting point. It is not legal advice and must be reviewed and validated by a qualified professional before use.',
    ];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PdfGenerator $pdf,
        private readonly ErasureService $erasure,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createActivity(array $data): ProcessingActivity
    {
        return ProcessingActivity::create([...$data, 'family_id' => (string) Str::uuid7(), 'version' => 1, 'status' => ProcessingActivity::Draft]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateActivity(ProcessingActivity $activity, array $data): ProcessingActivity
    {
        if ($activity->status !== ProcessingActivity::Draft) {
            throw AssociationException::conflict(__('association_privacy.activity_frozen'));
        }

        $activity->update($data);

        return $activity;
    }

    /**
     * Freezes a draft; the final version it replaces becomes superseded.
     */
    public function finalize(ProcessingActivity $activity): ProcessingActivity
    {
        if ($activity->status !== ProcessingActivity::Draft) {
            throw AssociationException::conflict(__('association_privacy.activity_frozen'));
        }

        return DB::transaction(static function () use ($activity): ProcessingActivity {
            ProcessingActivity::query()->where('family_id', $activity->family_id)->where('status', ProcessingActivity::Final)->get()
                ->each(static fn (ProcessingActivity $previous) => $previous->update(['status' => ProcessingActivity::Superseded]));

            $activity->update(['status' => ProcessingActivity::Final, 'finalized_at' => now()]);

            return $activity;
        });
    }

    /**
     * A new draft version copied from a final one.
     */
    public function newVersion(ProcessingActivity $activity): ProcessingActivity
    {
        if ($activity->status === ProcessingActivity::Draft) {
            throw AssociationException::conflict(__('association_privacy.activity_is_draft'));
        }

        if (ProcessingActivity::query()->where('family_id', $activity->family_id)->where('status', ProcessingActivity::Draft)->exists()) {
            throw AssociationException::conflict(__('association_privacy.draft_exists'));
        }

        $copy = $activity->replicate(['finalized_at', 'status', 'version', 'previous_version_id']);
        $copy->fill([
            'version' => (int) ProcessingActivity::query()->where('family_id', $activity->family_id)->max('version') + 1,
            'previous_version_id' => $activity->id,
            'status' => ProcessingActivity::Draft,
        ]);
        $copy->save();

        return $copy;
    }

    /**
     * @return Collection<int, ProcessingActivity> the versions in force
     */
    public function currentActivities(): Collection
    {
        return ProcessingActivity::query()->where('status', ProcessingActivity::Final)->orderBy('name')->get();
    }

    /**
     * The register of the processing activities as rows, with the identity of the controller and of the DPO.
     *
     * @return array{controller: array<string, ?string>, dpo: ?array<string, ?string>, headers: list<string>, rows: list<list<string>>}
     */
    public function register(): array
    {
        $headers = ['name', 'version', 'purposes', 'legal_bases', 'data_subjects', 'data_categories', 'special_categories', 'recipients', 'transfers_outside_eu', 'retention', 'security_measures', 'finalized_at'];
        $rows = $this->currentActivities()->map(static fn (ProcessingActivity $activity): array => [
            $activity->name,
            (string) $activity->version,
            implode('; ', $activity->purposes ?? []),
            implode('; ', array_map(static fn (string $basis): string => (string) __('association_privacy.legal_basis.'.$basis), $activity->legal_bases ?? [])),
            implode('; ', $activity->data_subjects ?? []),
            implode('; ', $activity->data_categories ?? []),
            $activity->special_categories ? 'yes' : 'no',
            implode('; ', $activity->recipients ?? []),
            (string) ($activity->transfers_outside_eu ?? ''),
            (string) $activity->retention,
            (string) ($activity->security_measures ?? ''),
            (string) $activity->finalized_at?->toDateString(),
        ])->values()->all();

        return [
            'controller' => $this->controller(),
            'dpo' => $this->dpo(),
            'headers' => array_map(static fn (string $header): string => (string) __('association_privacy.register_columns.'.$header), $headers),
            'rows' => $rows,
        ];
    }

    public function registerCsv(): string
    {
        $register = $this->register();

        return CsvFile::write($register['headers'], $register['rows']);
    }

    public function registerPdf(): string
    {
        $register = $this->register();

        return $this->pdf->render('associations::pdf.book', [
            'tenant' => $this->tenantContext->getTenant(),
            'book' => ['title' => (string) __('association_privacy.register_title'), 'year' => null, 'headers' => $register['headers'], 'rows' => $register['rows']],
        ]);
    }

    /**
     * The privacy notice of an activity, composed from the data of the company.
     *
     * @return array{title: string, sections: list<array{heading: string, text: string}>}
     */
    public function notice(ProcessingActivity $activity): array
    {
        $controller = $this->controller();
        $dpo = $this->dpo();
        $sections = [
            ['heading' => (string) __('association_privacy.notice.controller'), 'text' => trim(implode(', ', array_filter([$controller['name'], $controller['address'], $controller['email']])))],
        ];

        if ($dpo !== null) {
            $sections[] = ['heading' => (string) __('association_privacy.notice.dpo'), 'text' => trim(implode(', ', array_filter([$dpo['name'], $dpo['email']])))];
        }

        $sections[] = ['heading' => (string) __('association_privacy.notice.purposes'), 'text' => implode("\n", array_map(static fn (string $purpose): string => '- '.$purpose, $activity->purposes ?? []))];
        $sections[] = ['heading' => (string) __('association_privacy.notice.legal_bases'), 'text' => implode("\n", array_map(static fn (string $basis): string => '- '.__('association_privacy.legal_basis.'.$basis), $activity->legal_bases ?? []))];
        $sections[] = ['heading' => (string) __('association_privacy.notice.data'), 'text' => implode(', ', $activity->data_categories ?? [])];
        $sections[] = ['heading' => (string) __('association_privacy.notice.recipients'), 'text' => ($activity->recipients ?? []) === [] ? (string) __('association_privacy.notice.no_recipients') : implode(', ', $activity->recipients)];
        $sections[] = ['heading' => (string) __('association_privacy.notice.transfers'), 'text' => $activity->transfers_outside_eu ?: (string) __('association_privacy.notice.no_transfers')];
        $sections[] = ['heading' => (string) __('association_privacy.notice.retention'), 'text' => (string) $activity->retention];
        $sections[] = ['heading' => (string) __('association_privacy.notice.rights'), 'text' => (string) __('association_privacy.notice.rights_text')];

        if (in_array('consent', $activity->legal_bases ?? [], true)) {
            $sections[] = ['heading' => (string) __('association_privacy.notice.withdrawal'), 'text' => (string) __('association_privacy.notice.withdrawal_text')];
        }

        return ['title' => (string) __('association_privacy.notice.title', ['name' => $activity->name]), 'sections' => $sections];
    }

    public function noticePdf(ProcessingActivity $activity): string
    {
        return $this->pdf->render('associations::pdf.privacy-document', ['tenant' => $this->tenantContext->getTenant(), 'document' => $this->notice($activity)]);
    }

    /**
     * The letter of appointment of a processor, a DPO or an authorized person.
     */
    public function appointmentPdf(PrivacySubject $subject): string
    {
        if (! in_array($subject->role, ['processor', 'dpo', 'authorized'], true)) {
            throw new AssociationException(__('association_privacy.no_appointment_for_role'));
        }

        $controller = $this->controller();
        $document = [
            'title' => (string) __('association_privacy.appointment.title_'.$subject->role),
            'sections' => [
                ['heading' => (string) __('association_privacy.appointment.parties'), 'text' => (string) __('association_privacy.appointment.parties_text', ['controller' => $controller['name'], 'name' => $subject->name])],
                ['heading' => (string) __('association_privacy.appointment.scope'), 'text' => (string) ($subject->scope ?: '-')],
                ['heading' => (string) __('association_privacy.appointment.duties'), 'text' => (string) __('association_privacy.appointment.duties_'.$subject->role)],
                ['heading' => (string) __('association_privacy.appointment.date'), 'text' => (string) ($subject->appointed_on?->format('d/m/Y') ?? now()->format('d/m/Y'))],
            ],
        ];

        return $this->pdf->render('associations::pdf.privacy-document', ['tenant' => $this->tenantContext->getTenant(), 'document' => $document]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordConsent(array $data, ?string $userId): Consent
    {
        $activity = ProcessingActivity::query()->findOrFail($data['activity_id']);

        if (! in_array('consent', $activity->legal_bases ?? [], true)) {
            throw new AssociationException(__('association_privacy.consent_not_the_basis'));
        }

        return Consent::create([
            ...$data,
            'given_at' => $data['given_at'] ?? now(),
            'proof_hash' => hash('sha256', (string) $data['proof']),
            'recorded_by' => $userId,
        ]);
    }

    public function withdrawConsent(Consent $consent): Consent
    {
        if ($consent->withdrawn_at !== null) {
            throw AssociationException::conflict(__('association_privacy.consent_withdrawn'));
        }

        $consent->update(['withdrawn_at' => now()]);

        return $consent;
    }

    /**
     * A request is due one month after receipt (Article 12(3) of the Regulation); the month can be extended by two
     * further months, with a reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function receiveRequest(array $data): DataSubjectRequest
    {
        $received = CarbonImmutable::parse($data['received_at'] ?? now());

        return DataSubjectRequest::create([...$data, 'received_at' => $received, 'due_on' => $received->addMonthNoOverflow()->toDateString(), 'status' => 'received']);
    }

    public function extendRequest(DataSubjectRequest $request, string $reason): DataSubjectRequest
    {
        $this->assertOpen($request);

        if ($request->extended_due_on !== null) {
            throw AssociationException::conflict(__('association_privacy.already_extended'));
        }

        $request->update(['status' => 'extended', 'extension_reason' => $reason, 'extended_due_on' => $request->due_on->addMonthsNoOverflow(2)->toDateString()]);

        return $request;
    }

    /**
     * Completes a request. An erasure runs the erasure of the person and stores its report; a restriction marks the
     * person as restricted.
     */
    public function completeRequest(DataSubjectRequest $request, string $outcome, ?string $userId): DataSubjectRequest
    {
        $this->assertOpen($request);
        $report = null;

        if (in_array($request->kind, ['erasure', 'restriction', 'access', 'portability'], true) && $request->member === null) {
            throw new AssociationException(__('association_privacy.request_needs_member'));
        }

        if ($request->kind === 'erasure') {
            $report = $this->erasure->erase($request->member);
        }

        if ($request->kind === 'restriction') {
            $request->member->update(['processing_restricted_at' => now()]);
        }

        $request->update(['status' => 'completed', 'outcome' => $outcome, 'erasure_report' => $report, 'handled_by' => $userId, 'completed_at' => now()]);

        return $request;
    }

    public function rejectRequest(DataSubjectRequest $request, string $outcome, ?string $userId): DataSubjectRequest
    {
        $this->assertOpen($request);
        $request->update(['status' => 'rejected', 'outcome' => $outcome, 'handled_by' => $userId, 'completed_at' => now()]);

        return $request;
    }

    /**
     * The data of the person in a structured, machine-readable form, for access and portability. Health data is
     * included only when the reader may see it.
     *
     * @return array<string, mixed>
     */
    public function exportMember(Member $member, bool $includeHealth): array
    {
        $contact = $member->contact;
        $personal = [
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'tax_code' => $member->tax_code,
            'birth_date' => $member->birth_date,
            'birth_place' => $member->birth_place,
            'gender' => $member->gender,
            'joined_on' => $member->joined_on?->toDateString(),
            'custom_fields' => $member->custom_fields,
        ];

        if ($includeHealth) {
            $personal['health_notes'] = $member->health_notes;
            $personal['allergies'] = $member->allergies;
        }

        return [
            'person' => $personal,
            'memberships' => Membership::query()->where('member_id', $member->id)->get(['card_number', 'status', 'valid_from', 'valid_to'])->toArray(),
            'fees' => FeeAssignment::query()->where('member_id', $member->id)->get(['description', 'net_amount', 'starts_on', 'ends_on'])->toArray(),
            'receipts' => Receipt::query()->where('member_id', $member->id)->get(['number', 'issued_on', 'amount', 'description'])->toArray(),
            'consents' => Consent::query()->where('member_id', $member->id)->get(['purpose', 'method', 'text_version', 'given_at', 'withdrawn_at'])->toArray(),
        ];
    }

    /**
     * @return array{name: ?string, address: ?string, email: ?string}
     */
    private function controller(): array
    {
        $subject = PrivacySubject::query()->where('role', 'controller')->whereNull('ended_on')->first();
        $tenant = $this->tenantContext->getTenant();

        return [
            'name' => $subject?->name ?? ($tenant?->legal_name ?: $tenant?->name),
            'address' => $subject?->address,
            'email' => $subject?->email ?? $tenant?->pec_email,
        ];
    }

    /**
     * @return ?array{name: ?string, email: ?string}
     */
    private function dpo(): ?array
    {
        $subject = PrivacySubject::query()->where('role', 'dpo')->whereNull('ended_on')->first();

        return $subject === null ? null : ['name' => $subject->name, 'email' => $subject->email];
    }

    private function assertOpen(DataSubjectRequest $request): void
    {
        if (! in_array($request->status, DataSubjectRequest::OpenStatuses, true)) {
            throw AssociationException::conflict(__('association_privacy.request_closed'));
        }
    }
}
