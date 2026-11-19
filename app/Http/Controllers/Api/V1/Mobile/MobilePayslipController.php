<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Events\Mobile\PayslipRead;
use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\PayslipDocument;
use App\Models\User;
use App\Services\Mobile\MobilePushService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Employee document drawer: payslips (LUL), CU and Swiss Lohnausweis with read receipts. */
class MobilePayslipController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    private const PUSH = [
        'it' => 'Nuovo documento disponibile',
        'de' => 'Neues Dokument verfügbar',
        'fr' => 'Nouveau document disponible',
        'en' => 'New document available',
    ];

    #[OA\Get(
        path: '/api/v1/mobile/payslips',
        operationId: 'listMobilePayslips',
        description: 'Published payslips and tax documents of the authenticated user, newest period first (paginated, 50 per page).',
        summary: 'List my payslips',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Document type', schema: new OA\Schema(type: 'string', enum: ['payslip', 'cu', 'lohnausweis'])), new OA\Parameter(name: 'year', in: 'query', required: false, description: 'Period year', schema: new OA\Schema(type: 'integer', minimum: 2000, maximum: 2100))],
        responses: [
            new OA\Response(response: 200, description: 'Documents', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $params = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', PayslipDocument::TYPES)],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
        ]);

        $docs = PayslipDocument::where('user_id', $request->user()->id)
            ->whereNotNull('published_at')
            ->when(isset($params['type']), fn ($q) => $q->where('document_type', $params['type']))
            ->when(isset($params['year']), fn ($q) => $q->where('period_year', $params['year']))
            ->orderByDesc('period_year')->orderByDesc('period_month')
            ->paginate(50);

        return $this->successResponse($docs->items(), meta: ['current_page' => $docs->currentPage(), 'last_page' => $docs->lastPage(), 'total' => $docs->total()]);
    }

    /** Streams the file and records the read receipt (first open only). */
    #[OA\Get(
        path: '/api/v1/mobile/payslips/{id}/download',
        operationId: 'downloadMobilePayslip',
        description: 'Streams the PDF of an own published document and records the read receipt on first open.',
        summary: 'Download payslip',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the document', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'The PDF file', content: new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function download(Request $request, string $id): StreamedResponse|JsonResponse
    {
        $doc = $this->ownDocument($request, $id);

        if (! Storage::disk('local')->exists($doc->file_path)) {
            return $this->problemDetailsResponse('Document Missing', 'The document file is not available.', Response::HTTP_NOT_FOUND);
        }

        $this->markRead($request, $doc);

        return Storage::disk('local')->download($doc->file_path, $doc->title.'.pdf');
    }

    /** Explicit read receipt (e.g. the app opened a locally cached copy). */
    #[OA\Post(
        path: '/api/v1/mobile/payslips/{id}/read-receipt',
        operationId: 'recordMobilePayslipReadReceipt',
        description: 'Explicit read receipt, e.g. when the app opened a locally cached copy. Only the first read is stored.',
        summary: 'Record read receipt',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the document', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Read receipt recorded', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', properties: [new OA\Property(property: 'id', type: 'string', format: 'uuid'), new OA\Property(property: 'read_at', type: 'string', format: 'date-time')]), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function readReceipt(Request $request, string $id): JsonResponse
    {
        $doc = $this->markRead($request, $this->ownDocument($request, $id));

        return $this->successResponse(['id' => $doc->id, 'read_at' => $doc->read_at], 'Read receipt recorded');
    }

    /** HR: publish a document to an employee's drawer and notify them. */
    #[OA\Post(
        path: '/api/v1/mobile/admin/payslips',
        operationId: 'publishMobilePayslip',
        description: 'HR: uploads a PDF to an employee document drawer and notifies them by push. Requires the manage-payslips ability.',
        summary: 'Publish payslip',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(type: 'object', required: ['user_id', 'document_type', 'period_year', 'title', 'file'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'document_type', type: 'string', enum: ['payslip', 'cu', 'lohnausweis']), new OA\Property(property: 'period_year', type: 'integer', minimum: 2000, maximum: 2100), new OA\Property(property: 'period_month', type: 'integer', minimum: 1, maximum: 12, nullable: true), new OA\Property(property: 'title', type: 'string', maxLength: 191), new OA\Property(property: 'country', type: 'string', enum: ['IT', 'CH'], nullable: true), new OA\Property(property: 'file', type: 'string', format: 'binary', description: 'PDF, max 10 MB')]))),
        responses: [
            new OA\Response(response: 201, description: 'Document published', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(Request $request, MobilePushService $push): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-payslips');

        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'document_type' => ['required', 'in:'.implode(',', PayslipDocument::TYPES)],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['nullable', 'integer', 'between:1,12'],
            'title' => ['required', 'string', 'max:191'],
            'country' => ['nullable', 'in:IT,CH'],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $tenantId = (string) app(TenantContext::class)->getTenantId();
        $path = $request->file('file')->store("payslips/{$tenantId}/{$data['user_id']}", 'local');

        $doc = PayslipDocument::create([
            'user_id' => $data['user_id'],
            'document_type' => $data['document_type'],
            'period_year' => $data['period_year'],
            'period_month' => $data['period_month'] ?? null,
            'title' => $data['title'],
            'country' => $data['country'] ?? null,
            'file_path' => $path,
            'published_at' => now(),
        ]);

        $lang = substr((string) User::whereKey($data['user_id'])->value('locale'), 0, 2);
        $push->sendToUser($data['user_id'], self::PUSH[$lang] ?? self::PUSH['en'], $data['title'], [
            'type' => 'document_published',
            'document_id' => $doc->id,
            'document_type' => $doc->document_type,
        ]);

        return $this->successResponse($doc, 'Document published', Response::HTTP_CREATED);
    }

    private function ownDocument(Request $request, string $id): PayslipDocument
    {
        return PayslipDocument::where('user_id', $request->user()->id)
            ->whereNotNull('published_at')
            ->findOrFail($id);
    }

    private function markRead(Request $request, PayslipDocument $doc): PayslipDocument
    {
        if ($doc->read_at === null) {
            $doc->forceFill([
                'read_at' => now(),
                'read_ip' => $request->ip(),
                'read_device_id' => $this->currentDevice($request)?->device_id,
            ])->save();

            PayslipRead::dispatch($doc);
        }

        return $doc;
    }
}
