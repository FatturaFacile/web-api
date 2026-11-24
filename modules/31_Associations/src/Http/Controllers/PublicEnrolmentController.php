<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Associations\Enums\ApplicationStatus;
use Modules\Associations\Exceptions\AssociationException;
use Modules\Associations\Http\Requests\SubmitEnrolmentRequest;
use Modules\Associations\Models\EnrolmentApplication;
use Modules\Associations\Models\EnrolmentForm;
use Modules\Associations\Models\EnrolmentFormDocument;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\OpenApi\OperationArguments;
use Modules\Associations\Services\EnrolmentService;
use Modules\Associations\Services\PrivateFiles;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public side of online enrolment. The tenant comes from the token of the link, through the middleware.
 */
final class PublicEnrolmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly EnrolmentService $enrolment,
        private readonly PrivateFiles $files,
    ) {}

    #[AssociationGet('/api/v1/association-public/enrolment/{token}', 'showAssociationPublicForm', 'Show a public enrolment form', OperationArguments::Public)]
    public function show(Request $request): JsonResponse
    {
        $form = $this->form($request);

        return response()->json(['data' => [
            'association' => $form->tenant->name,
            'title' => $form->title,
            'description' => $form->description,
            'is_open' => $form->isOpen(),
            'fields' => $form->fields ?? [],
            'documents' => $form->documents->map(static fn (EnrolmentFormDocument $document): array => [
                'id' => $document->id,
                'title' => $document->title,
                'mode' => $document->mode,
                'has_template' => $document->path !== null,
            ]),
        ]]);
    }

    #[AssociationPost('/api/v1/association-public/enrolment/{token}', 'submitAssociationPublicForm', 'Submit an enrolment form', 'AssociationEnrolmentSubmission', OperationArguments::Public, multipart: true)]
    public function submit(SubmitEnrolmentRequest $request): JsonResponse
    {
        $form = $this->form($request);

        if (! $form->isOpen()) {
            throw AssociationException::conflict(__('associations.form_closed'));
        }

        $result = $this->enrolment->submit($form, $request->person(), $request->uploads(), $request->ip());

        // The same answer for a new person and for a known one: the form does not tell who is in the registry.
        return response()->json(['data' => ['tracking_code' => $result['tracking_code'], 'received' => true]], Response::HTTP_CREATED);
    }

    #[AssociationGet('/api/v1/association-public/enrolment/{token}/documents/{documentId}', 'downloadAssociationFormTemplate', 'Download a template of a form', OperationArguments::Public, file: true)]
    public function document(Request $request, string $token, string $documentId): StreamedResponse
    {
        $document = $this->form($request)->documents()->findOrFail($documentId);

        return $this->files->download($document->disk, $document->path, $document->original_name);
    }

    #[AssociationGet('/api/v1/association-public/enrolment/{token}/applications/{trackingCode}', 'trackAssociationApplication', 'Read the state of an application with its tracking code', OperationArguments::Public)]
    public function track(Request $request, string $token, string $trackingCode): JsonResponse
    {
        $application = EnrolmentApplication::query()
            ->where('form_id', $this->form($request)->id)
            ->where('tracking_hash', hash('sha256', $trackingCode))
            ->firstOrFail();

        return response()->json(['data' => ['status' => $application->status === ApplicationStatus::PendingBoard ? ApplicationStatus::Submitted : $application->status]]);
    }

    private function form(Request $request): EnrolmentForm
    {
        /** @var EnrolmentForm */
        return $request->attributes->get('enrolmentForm');
    }
}
