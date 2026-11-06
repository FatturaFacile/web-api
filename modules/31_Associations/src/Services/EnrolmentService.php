<?php

declare(strict_types=1);

namespace Modules\Associations\Services;

use App\Models\Contact;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Associations\Contracts\BoardDecisionGateway;
use Modules\Associations\Enums\ApplicationStatus;
use Modules\Associations\Enums\ApprovalMode;
use Modules\Associations\Enums\DocumentMode;
use Modules\Associations\Enums\MembershipStatus;
use Modules\Associations\Enums\MemberStatus;
use Modules\Associations\Enums\RegistryKind;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\EnrolmentUpload;
use Modules\Associations\Models\Guardianship;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\Membership;
use Modules\Associations\Models\RegistryType;
use Modules\Associations\Support\BlindIndex;

/**
 * Online enrolment: from the public form to a candidate in the registry, then to an active membership, automatically
 * or after the decision of the board. Data sent to a public form never changes an existing person: when the tax code
 * matches somebody, the application waits for the staff whatever the form says.
 */
final class EnrolmentService
{
    public const array PersonFields = ['first_name', 'last_name', 'email', 'phone', 'birth_date', 'birth_place', 'gender', 'tax_code'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RegistryService $registry,
        private readonly MembershipService $memberships,
        private readonly GuardianService $guardians,
        private readonly PrivateFiles $files,
        private readonly BoardDecisionGateway $board,
        private readonly AreaTokenService $areaTokens,
        private readonly AssociationMailer $mailer,
    ) {}

    /**
     * @param  array<string, mixed>  $data  the validated form
     * @param  array<string, UploadedFile>  $uploads  by form document id
     * @return array{application: EnrolmentApplication, tracking_code: string}
     */
    public function submit(EnrolmentForm $form, array $data, array $uploads, ?string $ip): array
    {
        $trackingCode = Str::random(40);

        $application = DB::transaction(function () use ($form, $data, $uploads, $ip, $trackingCode): EnrolmentApplication {
            $hash = BlindIndex::taxCode($data['tax_code'], (string) $this->tenantContext->getTenantId());
            $known = Member::query()->where('tax_code_hash', $hash)->first();
            $member = $known ?? $this->createCandidate($data);
            $membership = $this->membershipFor($member, $form);

            $application = EnrolmentApplication::create([
                'form_id' => $form->id,
                'member_id' => $member->id,
                'membership_id' => $membership?->id,
                'status' => ApplicationStatus::Submitted,
                'payload' => $data,
                'tracking_hash' => hash('sha256', $trackingCode),
                'submitted_ip' => $ip,
            ]);

            $this->storeUploads($application, $form, $uploads);

            if ($known === null) {
                $this->route($application, $form);
            }

            return $application;
        });

        $application->refresh();

        if ($application->status === ApplicationStatus::Approved) {
            $this->welcome($application);
        } else {
            $this->mailer->send((string) $data['email'], __('associations.mail.application_subject'), [__('associations.mail.application_line', ['title' => $form->title])]);
        }

        return ['application' => $application, 'tracking_code' => $trackingCode];
    }

    public function approve(EnrolmentApplication $application, ?string $decidedBy, ?string $reference = null, ?string $notes = null): EnrolmentApplication
    {
        $approved = DB::transaction(fn (): EnrolmentApplication => $this->decide($application, true, $decidedBy, $reference, $notes));

        $this->welcome($approved);

        return $approved;
    }

    public function reject(EnrolmentApplication $application, ?string $decidedBy, ?string $reference = null, ?string $notes = null): EnrolmentApplication
    {
        return DB::transaction(fn (): EnrolmentApplication => $this->decide($application, false, $decidedBy, $reference, $notes));
    }

    /**
     * What the governance module calls once the board has decided.
     */
    public function applyBoardDecision(EnrolmentApplication $application, bool $approved, ?string $reference = null, ?string $decidedBy = null, ?string $notes = null): EnrolmentApplication
    {
        return $approved
            ? $this->approve($application, $decidedBy, $reference, $notes)
            : $this->reject($application, $decidedBy, $reference, $notes);
    }

    private function route(EnrolmentApplication $application, EnrolmentForm $form): void
    {
        $needsBoard = $form->approval_mode === ApprovalMode::Board || $form->membershipType->requires_board_decision;

        if (! $needsBoard) {
            $this->decide($application, true, null, 'automatic', null);

            return;
        }

        $request = $this->board->request($application);
        $application->update(['status' => ApplicationStatus::PendingBoard, 'decision_reference' => $request->reference]);
    }

    private function decide(EnrolmentApplication $application, bool $approved, ?string $decidedBy, ?string $reference, ?string $notes): EnrolmentApplication
    {
        /** @var EnrolmentApplication $locked */
        $locked = EnrolmentApplication::query()->whereKey($application->id)->lockForUpdate()->firstOrFail();

        if (! in_array($locked->status, [ApplicationStatus::Submitted, ApplicationStatus::PendingBoard], true)) {
            throw AssociationException::conflict(__('associations.application_already_decided'));
        }

        $membership = $locked->membership;

        if ($approved) {
            if ($membership !== null) {
                $this->memberships->activate($membership);
            }
        } elseif ($membership !== null && $membership->status === MembershipStatus::Pending) {
            $this->memberships->reject($membership, $notes);

            if ($locked->member?->status === MemberStatus::Candidate) {
                $locked->member->update(['status' => MemberStatus::Inactive]);
            }
        }

        $locked->update([
            'status' => $approved ? ApplicationStatus::Approved : ApplicationStatus::Rejected,
            'decided_by' => $decidedBy,
            'decided_at' => now(),
            'decision_reference' => $reference ?? $locked->decision_reference,
            'decision_notes' => $notes,
        ]);

        return $locked;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCandidate(array $data): Member
    {
        $member = $this->registry->create([
            ...array_intersect_key($data, array_flip(self::PersonFields)),
            'status' => MemberStatus::Candidate,
            'joined_on' => null,
            'custom_fields' => [],
        ]);

        $candidateType = RegistryType::query()->where('kind', RegistryKind::Candidate)->where('is_active', true)->first();

        if ($candidateType !== null) {
            $this->registry->assignType($member, $candidateType);
        }

        if ($member->isMinor()) {
            $this->linkGuardian($member, $data);
        }

        return $member;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function linkGuardian(Member $minor, array $data): void
    {
        $email = strtolower(trim((string) $data['guardian_email']));
        $existing = Contact::query()
            ->where('email', $email)
            ->whereIn('id', Guardianship::query()->select('guardian_contact_id'))
            ->first();

        $this->guardians->link($minor, array_filter([
            'contact_id' => $existing?->id,
            'first_name' => $data['guardian_first_name'],
            'last_name' => $data['guardian_last_name'],
            'email' => $email,
            'phone' => $data['guardian_phone'] ?? null,
            'relationship' => $data['guardian_relationship'] ?? 'parent',
            'is_primary' => true,
        ], static fn (mixed $value): bool => $value !== null));
    }

    private function membershipFor(Member $member, EnrolmentForm $form): ?Membership
    {
        $current = Membership::query()
            ->where('member_id', $member->id)
            ->where('year_id', $form->year_id)
            ->where('membership_type_id', $form->membership_type_id)
            ->whereNotIn('status', [MembershipStatus::Rejected, MembershipStatus::Ceased])
            ->first();

        return $current ?? $this->memberships->request($member, $form->year, $form->membershipType);
    }

    /**
     * @param  array<string, UploadedFile>  $uploads
     */
    private function storeUploads(EnrolmentApplication $application, EnrolmentForm $form, array $uploads): void
    {
        foreach ($form->documents as $document) {
            $file = $uploads[$document->id] ?? null;

            if ($file === null || $document->mode === DocumentMode::DownloadOnly) {
                continue;
            }

            $stored = $this->files->store($file, (string) $application->tenant_id, "enrolment/{$application->id}");
            EnrolmentUpload::create([...$stored, 'application_id' => $application->id, 'form_document_id' => $document->id]);
        }
    }

    private function welcome(EnrolmentApplication $application): void
    {
        $member = $application->member;

        if ($member === null) {
            return;
        }

        $contact = $this->areaContact($member);

        if ($contact?->email !== null && $contact->email !== '') {
            $this->areaTokens->issue($contact);
        }
    }

    /**
     * The minor reaches the area through the guardian.
     */
    private function areaContact(Member $member): ?Contact
    {
        if (! $member->isMinor(CarbonImmutable::today())) {
            return $member->contact;
        }

        return $member->guardianships()->active()->orderByDesc('is_primary')->first()?->guardian;
    }
}
