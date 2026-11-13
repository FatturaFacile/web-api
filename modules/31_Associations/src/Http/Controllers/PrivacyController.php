<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Modules\Associations\Http\Requests\OutcomeRequest;
use Modules\Associations\Http\Requests\ReasonRequest;
use Modules\Associations\Http\Requests\ReceiveDataSubjectRequest;
use Modules\Associations\Http\Requests\RecordConsentRequest;
use Modules\Associations\Http\Requests\SaveProcessingActivityRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\Consent;
use Modules\Associations\Models\DataSubjectRequest;
use Modules\Associations\Models\Member;
use Modules\Associations\Models\PrivacySubject;
use Modules\Associations\Models\ProcessingActivity;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\ErasureService;
use Modules\Associations\Services\PrivacyService;
use Modules\Associations\Support\AssociationCapability;

/**
 * Data protection: processing activities in versions, the register of Article 30, notices, appointments, consents and
 * the requests of the data subjects. Subjects and assets are maintained through the privacy catalogs. Every generated
 * text is a template: the response says so in its meta.
 */
final class PrivacyController extends AssociationController
{
    public function __construct(private readonly PrivacyService $privacy) {}

    #[AssociationGet('/api/v1/associations/privacy/activities', 'listAssociationProcessingActivities', 'Processing activities, every version or only those in force', query: ['current', 'per_page'], response: 'AssociationProcessingActivity')]
    public function activities(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Privacy);

        return AssociationRecordResource::collection(ProcessingActivity::query()
            ->when($request->boolean('current'), static fn ($query) => $query->where('status', ProcessingActivity::Final))
            ->orderBy('name')->orderByDesc('version')
            ->gridPaginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/privacy/activities', 'createAssociationProcessingActivity', 'Describe a processing activity as a draft', 'AssociationProcessingActivityRequest', response: 'AssociationProcessingActivity')]
    public function storeActivity(SaveProcessingActivityRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);

        return (new AssociationRecordResource($this->privacy->createActivity($request->validated())))->response()->setStatusCode(201);
    }

    #[AssociationPut('/api/v1/associations/privacy/activities/{id}', 'updateAssociationProcessingActivity', 'Change a draft; a final version is frozen', 'AssociationProcessingActivityRequest', response: 'AssociationProcessingActivity')]
    public function updateActivity(SaveProcessingActivityRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Privacy);

        return new AssociationRecordResource($this->privacy->updateActivity(ProcessingActivity::query()->findOrFail($id), $request->validated()));
    }

    #[AssociationPost('/api/v1/associations/privacy/activities/{id}/finalize', 'finalizeAssociationProcessingActivity', 'Freeze a draft; the previous final version becomes superseded', response: 'AssociationProcessingActivity')]
    public function finalize(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Privacy);

        return new AssociationRecordResource($this->privacy->finalize(ProcessingActivity::query()->findOrFail($id)));
    }

    #[AssociationPost('/api/v1/associations/privacy/activities/{id}/versions', 'versionAssociationProcessingActivity', 'Start a new draft version from a final one', response: 'AssociationProcessingActivity')]
    public function newVersion(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);

        return (new AssociationRecordResource($this->privacy->newVersion(ProcessingActivity::query()->findOrFail($id))))->response()->setStatusCode(201);
    }

    #[AssociationGet('/api/v1/associations/privacy/register', 'showAssociationProcessingRegister', 'The register of the processing activities in force, Article 30 of Regulation (EU) 2016/679', response: 'AssociationProcessingRegister')]
    public function register(): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);

        return response()->json(['data' => $this->privacy->register(), 'meta' => PrivacyService::TemplateMeta]);
    }

    #[AssociationGet('/api/v1/associations/privacy/register/csv', 'exportAssociationProcessingRegisterCsv', 'The register as CSV', file: true)]
    public function registerCsv(): Response
    {
        $this->allow(AssociationCapability::Privacy);

        return response($this->privacy->registerCsv(), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="processing-register.csv"']);
    }

    #[AssociationGet('/api/v1/associations/privacy/register/pdf', 'exportAssociationProcessingRegisterPdf', 'The register as PDF', file: true)]
    public function registerPdf(): Response
    {
        $this->allow(AssociationCapability::Privacy);

        return $this->pdf($this->privacy->registerPdf(), 'processing-register.pdf');
    }

    #[AssociationGet('/api/v1/associations/privacy/activities/{id}/notice', 'showAssociationPrivacyNotice', 'The privacy notice of an activity, composed from the data of the company. A template to validate', response: 'AssociationGeneratedDocument')]
    public function notice(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);

        return response()->json(['data' => $this->privacy->notice(ProcessingActivity::query()->findOrFail($id)), 'meta' => PrivacyService::TemplateMeta]);
    }

    #[AssociationGet('/api/v1/associations/privacy/activities/{id}/notice/pdf', 'printAssociationPrivacyNotice', 'The privacy notice as PDF, marked as a template', file: true)]
    public function noticePdf(string $id): Response
    {
        $this->allow(AssociationCapability::Privacy);

        return $this->pdf($this->privacy->noticePdf(ProcessingActivity::query()->findOrFail($id)), 'privacy-notice.pdf');
    }

    #[AssociationGet('/api/v1/associations/privacy/subjects/{id}/appointment', 'printAssociationPrivacyAppointment', 'Letter of appointment of a processor, a DPO or an authorized person, marked as a template', file: true)]
    public function appointment(string $id): Response
    {
        $this->allow(AssociationCapability::Privacy);

        return $this->pdf($this->privacy->appointmentPdf(PrivacySubject::query()->findOrFail($id)), 'appointment.pdf');
    }

    #[AssociationGet('/api/v1/associations/privacy/consents', 'listAssociationConsents', 'Consents recorded, for a person or an activity', query: ['member_id', 'activity_id', 'per_page'], response: 'AssociationRecord')]
    public function consents(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Privacy);

        return AssociationRecordResource::collection(Consent::query()
            ->when($request->filled('member_id'), static fn ($query) => $query->where('member_id', $request->string('member_id')->toString()))
            ->when($request->filled('activity_id'), static fn ($query) => $query->where('activity_id', $request->string('activity_id')->toString()))
            ->orderByDesc('given_at')
            ->gridPaginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/privacy/consents', 'recordAssociationConsent', 'Record a consent with the proof of how it was given', 'AssociationConsentRequest', response: 'AssociationRecord')]
    public function recordConsent(RecordConsentRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);

        return (new AssociationRecordResource($this->privacy->recordConsent($request->validated(), $this->actorId($request))))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/privacy/consents/{id}/withdraw', 'withdrawAssociationConsent', 'Record the withdrawal of a consent', response: 'AssociationRecord')]
    public function withdrawConsent(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Privacy);

        return new AssociationRecordResource($this->privacy->withdrawConsent(Consent::query()->findOrFail($id)));
    }

    #[AssociationGet('/api/v1/associations/privacy/requests', 'listAssociationDataSubjectRequests', 'Requests of the data subjects; open ones first, with their deadline', query: ['status', 'overdue', 'per_page'], response: 'AssociationRecord')]
    public function requests(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Privacy);

        return AssociationRecordResource::collection(DataSubjectRequest::query()
            ->when($request->filled('status'), static fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->boolean('overdue'), static fn ($query) => $query->whereIn('status', DataSubjectRequest::OpenStatuses)
                ->whereRaw('coalesce(extended_due_on, due_on) < ?', [now()->toDateString()]))
            ->orderBy('due_on')
            ->gridPaginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/privacy/requests', 'receiveAssociationDataSubjectRequest', 'Register a request of a data subject; it is due one month after receipt', 'AssociationDataSubjectRequest', response: 'AssociationRecord')]
    public function receive(ReceiveDataSubjectRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);

        return (new AssociationRecordResource($this->privacy->receiveRequest($request->validated())))->response()->setStatusCode(201);
    }

    #[AssociationPost('/api/v1/associations/privacy/requests/{id}/extend', 'extendAssociationDataSubjectRequest', 'Extend the deadline by two further months, with a reason', 'AssociationRequiredReasonRequest', response: 'AssociationRecord')]
    public function extend(ReasonRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Privacy);

        return new AssociationRecordResource($this->privacy->extendRequest(DataSubjectRequest::query()->findOrFail($id), (string) $request->validated('reason')));
    }

    #[AssociationGet('/api/v1/associations/privacy/requests/{id}/erasure-preview', 'previewAssociationErasure', 'What an erasure would delete and what the law obliges to keep', response: 'AssociationErasurePreview')]
    public function erasurePreview(string $id, ErasureService $erasure): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);
        $request = DataSubjectRequest::query()->with('member')->findOrFail($id);

        return response()->json(['data' => ['kept' => $request->member === null ? [] : $erasure->holds($request->member)]]);
    }

    #[AssociationPost('/api/v1/associations/privacy/requests/{id}/complete', 'completeAssociationDataSubjectRequest', 'Complete a request; an erasure runs within the limits of the legal retention', 'AssociationOutcomeRequest', response: 'AssociationRecord')]
    public function complete(OutcomeRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Privacy);

        return new AssociationRecordResource($this->privacy->completeRequest(DataSubjectRequest::query()->with('member')->findOrFail($id), (string) $request->validated('outcome'), $this->actorId($request)));
    }

    #[AssociationPost('/api/v1/associations/privacy/requests/{id}/reject', 'rejectAssociationDataSubjectRequest', 'Reject a request with the reason given to the data subject', 'AssociationOutcomeRequest', response: 'AssociationRecord')]
    public function reject(OutcomeRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Privacy);

        return new AssociationRecordResource($this->privacy->rejectRequest(DataSubjectRequest::query()->findOrFail($id), (string) $request->validated('outcome'), $this->actorId($request)));
    }

    #[AssociationGet('/api/v1/associations/privacy/requests/{id}/export', 'exportAssociationDataSubjectData', 'The data of the person as structured JSON, for access and portability', response: 'AssociationRecord')]
    public function export(Request $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Privacy);
        $subjectRequest = DataSubjectRequest::query()->findOrFail($id);
        $member = Member::query()->findOrFail((string) $subjectRequest->member_id);

        return response()->json(['data' => $this->privacy->exportMember($member, Gate::allows(AssociationCapability::Health->value))]);
    }
}
