<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Modules\Associations\Http\Requests\DecideApplicationRequest;
use Modules\Associations\Http\Requests\SaveEnrolmentFormRequest;
use Modules\Associations\Http\Requests\StoreFormDocumentRequest;
use Modules\Associations\Http\Resources\AssociationRecordResource;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\EnrolmentFormDocument;
use Modules\Associations\Models\EnrolmentUpload;
use Modules\Associations\OpenApi\AssociationDelete;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\AssociationPut;
use Modules\Associations\Services\EnrolmentService;
use Modules\Associations\Services\PrivateFiles;
use Modules\Associations\Support\AssociationCapability;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The staff side of online enrolment: forms and applications.
 */
final class EnrolmentAdminController extends AssociationController
{
    public function __construct(
        private readonly EnrolmentService $enrolment,
        private readonly PrivateFiles $files,
    ) {}

    #[AssociationGet('/api/v1/associations/enrolment-forms', 'listAssociationEnrolmentForms', 'List the enrolment forms')]
    public function forms(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Manage);

        return AssociationRecordResource::collection(EnrolmentForm::query()->with('documents')->orderByDesc('created_at')->paginate($this->perPage($request)));
    }

    #[AssociationPost('/api/v1/associations/enrolment-forms', 'createAssociationEnrolmentForm', 'Create an enrolment form and its public link', 'AssociationEnrolmentFormRequest')]
    public function storeForm(SaveEnrolmentFormRequest $request): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource(EnrolmentForm::create([...$request->validated(), 'public_token' => Str::random(48)]));
    }

    #[AssociationGet('/api/v1/associations/enrolment-forms/{id}', 'showAssociationEnrolmentForm', 'Show an enrolment form')]
    public function showForm(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource(EnrolmentForm::query()->with('documents')->findOrFail($id));
    }

    #[AssociationPut('/api/v1/associations/enrolment-forms/{id}', 'updateAssociationEnrolmentForm', 'Update an enrolment form', 'AssociationEnrolmentFormRequest')]
    public function updateForm(SaveEnrolmentFormRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);
        $form = EnrolmentForm::query()->findOrFail($id);
        $form->update($request->validated());

        return new AssociationRecordResource($form);
    }

    #[AssociationDelete('/api/v1/associations/enrolment-forms/{id}', 'deleteAssociationEnrolmentForm', 'Delete an enrolment form. Its link stops working')]
    public function destroyForm(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        EnrolmentForm::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[AssociationPost('/api/v1/associations/enrolment-forms/{id}/documents', 'addAssociationFormDocument', 'Add a required, optional or download-only document to a form', 'AssociationFormDocumentRequest', multipart: true)]
    public function storeFormDocument(StoreFormDocumentRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);
        $form = EnrolmentForm::query()->findOrFail($id);
        $file = $request->file('file');
        $stored = $file === null ? [] : $this->files->store($file, (string) $form->tenant_id, "forms/{$form->id}");
        unset($stored['mime_type'], $stored['size']);

        return new AssociationRecordResource(EnrolmentFormDocument::create([
            ...$stored,
            'form_id' => $form->id,
            'title' => $request->validated('title'),
            'mode' => $request->validated('mode'),
            'sort_order' => (int) $request->validated('sort_order'),
        ]));
    }

    #[AssociationDelete('/api/v1/associations/enrolment-form-documents/{id}', 'removeAssociationFormDocument', 'Remove a document from a form')]
    public function destroyFormDocument(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        EnrolmentFormDocument::query()->findOrFail($id)->delete();

        return response()->json(null, 204);
    }

    #[AssociationGet('/api/v1/associations/enrolment-applications', 'listAssociationApplications', 'List the applications', query: ['status', 'form_id'])]
    public function applications(Request $request): AnonymousResourceCollection
    {
        $this->allow(AssociationCapability::Manage);

        $applications = EnrolmentApplication::query()
            ->when($request->filled('status'), static fn ($query) => $query->where('status', (string) $request->query('status')))
            ->when($request->filled('form_id'), static fn ($query) => $query->where('form_id', (string) $request->query('form_id')))
            ->orderByDesc('created_at')
            ->paginate($this->perPage($request));

        return AssociationRecordResource::collection($applications);
    }

    #[AssociationGet('/api/v1/associations/enrolment-applications/{id}', 'showAssociationApplication', 'Show an application with its documents')]
    public function showApplication(string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);

        return new AssociationRecordResource(EnrolmentApplication::query()->with('uploads')->findOrFail($id));
    }

    #[AssociationPost('/api/v1/associations/enrolment-applications/{id}/approve', 'approveAssociationApplication', 'Approve an application, also to record a decision of the board', 'AssociationDecisionRequest')]
    public function approve(DecideApplicationRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);
        $application = EnrolmentApplication::query()->findOrFail($id);

        return new AssociationRecordResource($this->enrolment->approve($application, $this->actorId($request), $request->validated('reference'), $request->validated('notes')));
    }

    #[AssociationPost('/api/v1/associations/enrolment-applications/{id}/reject', 'rejectAssociationApplication', 'Reject an application, also to record a decision of the board', 'AssociationDecisionRequest')]
    public function reject(DecideApplicationRequest $request, string $id): AssociationRecordResource
    {
        $this->allow(AssociationCapability::Manage);
        $application = EnrolmentApplication::query()->findOrFail($id);

        return new AssociationRecordResource($this->enrolment->reject($application, $this->actorId($request), $request->validated('reference'), $request->validated('notes')));
    }

    #[AssociationGet('/api/v1/associations/enrolment-uploads/{id}/download', 'downloadAssociationApplicationUpload', 'Download a document uploaded with an application', file: true)]
    public function downloadUpload(string $id): StreamedResponse
    {
        $this->allow(AssociationCapability::Manage);
        $upload = EnrolmentUpload::query()->findOrFail($id);

        return $this->files->download($upload->disk, $upload->path, $upload->original_name);
    }
}
