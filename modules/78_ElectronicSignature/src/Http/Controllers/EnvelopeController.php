<?php

declare(strict_types=1);

namespace Modules\ElectronicSignature\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Modules\ElectronicSignature\Enums\AuditEvent;
use Modules\ElectronicSignature\Enums\SignableType;
use Modules\ElectronicSignature\Exceptions\SignatureException;
use Modules\ElectronicSignature\Http\Requests\CreateEnvelopeRequest;
use Modules\ElectronicSignature\Http\Requests\PreviewDocumentRequest;
use Modules\ElectronicSignature\Http\Requests\VoidEnvelopeRequest;
use Modules\ElectronicSignature\Http\Resources\AuditLogResource;
use Modules\ElectronicSignature\Http\Resources\EnvelopeResource;
use Modules\ElectronicSignature\Models\SignatureEnvelope;
use Modules\ElectronicSignature\Services\AuditCertificateService;
use Modules\ElectronicSignature\Services\EnvelopeService;
use Modules\ElectronicSignature\Services\EnvelopeStorage;
use Modules\ElectronicSignature\Services\SignatureAuditService;
use Modules\ElectronicSignature\Services\SignatureQueryService;
use OpenApi\Attributes as OA;

/**
 * Signature envelopes of the company: list, create (and send), follow, remind, void and download the original, the signed
 * document and the certificate of completion. Messages are translation keys (`signatures.message.*`).
 */
final class EnvelopeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly SignatureQueryService $queries,
        private readonly EnvelopeStorage $storage,
        private readonly SignatureAuditService $audit,
        private readonly AuditCertificateService $certificate,
    ) {}

    #[OA\Get(path: '/api/v1/signatures/envelopes', operationId: 'signaturesListEnvelopes', summary: 'List signature envelopes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 200, description: 'Envelopes')])]
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('signatures.view');
        $page = $this->queries->list($request->only(['status', 'level', 'jurisdiction', 'provider', 'signable_type', 'signable_id', 'search', 'per_page']));

        return $this->successResponse(EnvelopeResource::collection($page->items())->resolve(), 'signatures.message.listed', 200, [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ]);
    }

    #[OA\Post(path: '/api/v1/signatures/envelopes', operationId: 'signaturesCreateEnvelope', summary: 'Create (and optionally send) a signature envelope for a document', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 201, description: 'Envelope created')])]
    public function store(CreateEnvelopeRequest $request): JsonResponse
    {
        $envelope = $this->envelopes->create($request->validated(), $request->file('file'), $request->user(), $request->ip(), $request->userAgent());

        if ($request->boolean('send')) {
            $envelope = $this->envelopes->send($envelope, $request->user(), $request->ip(), $request->userAgent());
        }

        return $this->successResponse(new EnvelopeResource($envelope), $request->boolean('send') ? 'signatures.message.sent' : 'signatures.message.saved', 201);
    }

    #[OA\Get(path: '/api/v1/signatures/preview', operationId: 'signaturesPreviewDocument', summary: 'The PDF of a business document as it would be signed (for the placement of the signature boxes)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], responses: [new OA\Response(response: 200, description: 'The PDF')])]
    public function preview(PreviewDocumentRequest $request): Response
    {
        $document = $this->envelopes->preview(SignableType::from((string) $request->validated('signable_type')), (string) $request->validated('signable_id'), (string) ($request->validated('locale') ?? app()->getLocale()));

        return response($document->pdf, 200, [
            'Content-Type' => $document->format === 'pades' ? 'application/pdf' : 'application/octet-stream',
            'Content-Disposition' => 'inline; filename="preview.pdf"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    #[OA\Get(path: '/api/v1/signatures/envelopes/{id}', operationId: 'signaturesShowEnvelope', summary: 'Show a signature envelope with its recipients', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Envelope')])]
    public function show(string $id): JsonResponse
    {
        Gate::authorize('signatures.view');

        return $this->successResponse(new EnvelopeResource($this->find($id)->load('recipients')));
    }

    #[OA\Get(path: '/api/v1/signatures/envelopes/{id}/audit', operationId: 'signaturesEnvelopeAudit', summary: 'The audit trail of an envelope with the result of the hash-chain check', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Audit trail')])]
    public function audit(string $id): JsonResponse
    {
        Gate::authorize('signatures.view');
        $envelope = $this->find($id);

        return $this->successResponse(AuditLogResource::collection($envelope->auditLogs()->get())->resolve(), 'signatures.message.listed', 200, ['chain_valid' => $this->audit->verifyChain($envelope)]);
    }

    #[OA\Post(path: '/api/v1/signatures/envelopes/{id}/send', operationId: 'signaturesSendEnvelope', summary: 'Send a draft envelope to its recipients', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Envelope sent')])]
    public function send(Request $request, string $id): JsonResponse
    {
        Gate::authorize('signatures.send');

        return $this->successResponse(new EnvelopeResource($this->envelopes->send($this->find($id), $request->user(), $request->ip(), $request->userAgent())), 'signatures.message.sent');
    }

    #[OA\Post(path: '/api/v1/signatures/envelopes/{id}/remind', operationId: 'signaturesRemindEnvelope', summary: 'Send a reminder to the recipients whose turn it is', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Reminders sent')])]
    public function remind(Request $request, string $id): JsonResponse
    {
        Gate::authorize('signatures.send');
        $count = $this->envelopes->remind($this->find($id), $request->user(), $request->ip());

        return $this->successResponse(['reminded' => $count], 'signatures.message.reminded');
    }

    #[OA\Post(path: '/api/v1/signatures/envelopes/{id}/void', operationId: 'signaturesVoidEnvelope', summary: 'Void (cancel or revoke) an envelope', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Envelope voided')])]
    public function void(VoidEnvelopeRequest $request, string $id): JsonResponse
    {
        $envelope = $this->envelopes->void($this->find($id), $request->user(), $request->ip(), $request->validated('reason'));

        return $this->successResponse(new EnvelopeResource($envelope->load('recipients')), 'signatures.message.voided');
    }

    #[OA\Get(path: '/api/v1/signatures/envelopes/{id}/download/{kind}', operationId: 'signaturesDownloadEnvelope', summary: 'Download the original, the signed document or the certificate of completion', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Electronic Signatures'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'kind', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['original', 'signed', 'certificate']))], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function download(Request $request, string $id, string $kind): Response
    {
        Gate::authorize('signatures.view');
        $envelope = $this->find($id);
        $extension = $this->storage->extension($envelope);
        $name = (Str::slug($envelope->title) ?: 'document');

        [$content, $filename, $mime] = match ($kind) {
            'original' => [$this->storage->original($envelope), $name.'.'.($envelope->document_format === 'pades' ? 'pdf' : ($envelope->document_format === 'xades' ? 'xml' : 'bin')), $envelope->document_format === 'pades' ? 'application/pdf' : 'application/octet-stream'],
            'signed' => [$this->storage->signed($envelope), $name.'-signed.'.$extension, $envelope->document_format === 'pades' ? 'application/pdf' : 'application/octet-stream'],
            'certificate' => [$this->certificate->render($envelope->load(['recipients', 'auditLogs'])), $name.'-certificate.pdf', 'application/pdf'],
            default => throw new SignatureException('document_not_found', 404),
        };

        $this->audit->record($envelope, AuditEvent::DocumentDownloaded, ['type' => 'user', 'id' => (string) $request->user()->id, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()], null, ['kind' => $kind]);

        return response($content, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function find(string $id): SignatureEnvelope
    {
        return SignatureEnvelope::query()->findOrFail($id);
    }
}
