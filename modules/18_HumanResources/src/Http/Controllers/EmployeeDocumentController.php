<?php

declare(strict_types=1);

namespace Modules\HumanResources\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\StoredFileHeaders;
use App\Models\User;
use App\Support\Http\RequestedPerPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\HumanResources\Http\Requests\StoreEmployeeDocumentRequest;
use Modules\HumanResources\Http\Resources\EmployeeDocumentResource;
use Modules\HumanResources\Models\Employee;
use Modules\HumanResources\Models\EmployeeDocument;
use Modules\HumanResources\Services\EmployeeDocumentService;
use OpenApi\Attributes as OA;

/**
 * The personnel file of the employees for HR roles (module.access:hr): upload, list, download and removal.
 */
final class EmployeeDocumentController extends Controller
{
    public function __construct(private readonly EmployeeDocumentService $documents) {}

    /**
     * List the documents of an employee.
     */
    #[OA\Get(
        path: '/api/v1/hr/employees/{id}/documents',
        operationId: 'listHrEmployeeDocuments',
        description: 'Documents of the personnel file of an employee (metadata only), including those with health data.',
        summary: 'List employee documents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Documents'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function index(string $id): AnonymousResourceCollection
    {
        $employee = Employee::query()->findOrFail($id);

        return EmployeeDocumentResource::collection(EmployeeDocument::query()->where('employee_id', $employee->id)->orderByDesc('created_at')->gridPaginate(RequestedPerPage::resolve(25)));
    }

    /**
     * Upload a document to the personnel file.
     */
    #[OA\Post(
        path: '/api/v1/hr/employees/{id}/documents',
        operationId: 'storeHrEmployeeDocument',
        description: 'Upload a document (multipart/form-data) to the personnel file with its retention date. Documents with health data are always confidential; the employee sees a document only when visible_to_employee is true.',
        summary: 'Upload employee document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/HrEmployeeDocumentUpload'))),
        tags: ['HR Documents'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 201, description: 'Document stored', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function store(StoreEmployeeDocumentRequest $request, string $id): JsonResponse
    {
        $employee = Employee::query()->findOrFail($id);
        /** @var User $actor */
        $actor = $request->user();
        $document = $this->documents->store($employee, $request->file('file'), $request->safe()->except('file'), $actor);

        return response()->json(['data' => new EmployeeDocumentResource($document)], 201);
    }

    /**
     * Download a document of the personnel file.
     */
    #[OA\Get(
        path: '/api/v1/hr/employee-documents/{id}/download',
        operationId: 'downloadHrEmployeeDocument',
        description: 'Download the file of a personnel document (inline=1 shows PDFs and images in the browser).',
        summary: 'Download employee document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Documents'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/HrRecordId'),
            new OA\Parameter(name: 'inline', description: 'Show in the browser when the type allows it', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'The file', content: new OA\MediaType(mediaType: 'application/octet-stream', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/HrUnprocessable', response: 422),
        ]
    )]
    public function download(Request $request, string $id): Response
    {
        return self::fileResponse($this->documents, EmployeeDocument::query()->findOrFail($id), $request->boolean('inline'));
    }

    /**
     * Remove a document.
     */
    #[OA\Delete(
        path: '/api/v1/hr/employee-documents/{id}',
        operationId: 'deleteHrEmployeeDocument',
        description: 'Remove a document from the personnel file (soft delete); the file is erased by the retention purge.',
        summary: 'Delete employee document',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['HR Documents'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/HrRecordId')],
        responses: [
            new OA\Response(response: 204, description: 'Document removed'),
            new OA\Response(ref: '#/components/responses/HrUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/HrForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/HrNotFound', response: 404),
        ]
    )]
    public function destroy(string $id): Response
    {
        $this->documents->delete(EmployeeDocument::query()->findOrFail($id));

        return response()->noContent();
    }

    /**
     * The download response of a document, with safe headers.
     */
    public static function fileResponse(EmployeeDocumentService $documents, EmployeeDocument $document, bool $inline): Response
    {
        $content = $documents->content($document);

        return response($content, 200, StoredFileHeaders::for($document->mime_type, (string) ($document->original_name ?: $document->title), $inline) + [
            'Content-Length' => (string) strlen($content),
        ]);
    }
}
