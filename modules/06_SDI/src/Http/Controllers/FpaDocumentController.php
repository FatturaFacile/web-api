<?php

declare(strict_types=1);

namespace Modules\SDI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Invoice;
use App\Services\ItalianSdi\FatturaPaBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\SDI\Enums\FpaStatus;
use Modules\SDI\Enums\SdiNotificationType;
use Modules\SDI\Http\Requests\SimulateSdiNotificationRequest;
use Modules\SDI\Models\FpaDocument;
use Modules\SDI\Models\SdiConfiguration;
use Modules\SDI\Services\FatturaPaSchemaValidator;
use Modules\SDI\Services\FatturaPaStylesheetRenderer;
use Modules\SDI\Services\RejectedDocumentResubmissionService;
use Modules\SDI\Services\SandboxSimulatorService;
use Modules\SDI\Services\SogeiCongruityValidator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Electronic invoice envelopes (outbound and inbound), their SDI notification history and the pre-flight validation.
 */
final class FpaDocumentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly FatturaPaBuilderService $builder,
        private readonly FatturaPaSchemaValidator $schema,
        private readonly SogeiCongruityValidator $sogei,
        private readonly RejectedDocumentResubmissionService $resubmission,
        private readonly SandboxSimulatorService $simulator,
        private readonly FatturaPaStylesheetRenderer $renderer,
    ) {}

    #[OA\Get(path: '/api/v1/fpa-documents', operationId: 'listFpaDocuments', summary: 'List electronic invoice envelopes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 200, description: 'Envelopes with KPI counters in meta')])]
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'direction' => ['nullable', 'in:outbound,inbound'],
            'status' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = FpaDocument::query()
            ->when($filters['direction'] ?? null, fn ($q, string $v) => $q->where('direction', $v))
            ->when($filters['status'] ?? null, fn ($q, string $v) => $q->where('status', $v))
            ->latest()
            ->paginate((int) ($filters['per_page'] ?? 25));

        $counters = FpaDocument::query()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status');

        return $this->successResponse(
            $page->getCollection()->map(fn (FpaDocument $d): array => $this->present($d))->all(),
            'Electronic invoices retrieved',
            meta: [
                'total' => $page->total(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'kpi' => [
                    'ready' => (int) ($counters[FpaStatus::Validated->value] ?? 0) + (int) ($counters[FpaStatus::Signed->value] ?? 0),
                    'transmitted' => (int) ($counters[FpaStatus::Transmitted->value] ?? 0) + (int) ($counters[FpaStatus::Queued->value] ?? 0),
                    'delivered' => (int) ($counters[FpaStatus::Delivered->value] ?? 0),
                    'undelivered' => (int) ($counters[FpaStatus::Undelivered->value] ?? 0),
                    'rejected' => (int) ($counters[FpaStatus::Rejected->value] ?? 0),
                    'inbound_to_process' => (int) ($counters[FpaStatus::InboundReceived->value] ?? 0),
                ],
            ],
        );
    }

    #[OA\Get(path: '/api/v1/fpa-documents/{id}', operationId: 'showFpaDocument', summary: 'Show an envelope with its SDI timeline', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 200, description: 'Envelope and notifications')])]
    public function show(string $id): JsonResponse
    {
        $document = FpaDocument::query()->findOrFail($id);

        return $this->successResponse([
            ...$this->present($document),
            'notifications' => $document->notifications()->get()->map(fn ($n): array => [
                'id' => $n->id,
                'type' => $n->notification_type->value,
                'outcome_code' => $n->outcome_code,
                'error_codes' => $n->error_codes,
                'received_at' => $n->received_at?->toIso8601String(),
            ])->all(),
        ], 'Electronic invoice retrieved');
    }

    #[OA\Get(path: '/api/v1/fpa-documents/{id}/render', operationId: 'renderFpaDocument', summary: 'Render an envelope as readable HTML (style=ministerial|assosoftware)', parameters: [new OA\Parameter(name: 'style', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['ministerial', 'assosoftware']))], security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 200, description: 'HTML')])]
    public function render(Request $request, string $id): Response
    {
        $document = FpaDocument::query()->findOrFail($id);
        $xml = $document->xml_storage_path !== null ? (string) Storage::disk('local')->get($document->xml_storage_path) : '';

        return response($this->renderer->render($xml, in_array($request->query('style'), FatturaPaStylesheetRenderer::Styles, true) ? (string) $request->query('style') : 'ministerial'), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    #[OA\Post(path: '/api/v1/fpa-documents/{id}/resubmit', operationId: 'resubmitFpaDocument', summary: 'Create the linked revision of a rejected envelope (5 day window)', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 201, description: 'Revision created'), new OA\Response(response: 422, description: 'Not rejected or deadline passed')])]
    public function resubmit(string $id): JsonResponse
    {
        $document = FpaDocument::query()->findOrFail($id);

        try {
            $revision = $this->resubmission->createRevision($document);
        } catch (\DomainException $e) {
            return $this->problemDetailsResponse(title: 'resubmission_not_allowed', detail: $e->getMessage(), status: Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->successResponse($this->present($revision), 'Revision created', Response::HTTP_CREATED);
    }

    #[OA\Post(path: '/api/v1/fpa-documents/{id}/simulate', operationId: 'simulateFpaNotification', summary: 'Sandbox: simulate an SDI receipt', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 200, description: 'Receipt applied'), new OA\Response(response: 403, description: 'Sandbox disabled')])]
    public function simulate(SimulateSdiNotificationRequest $request, string $id): JsonResponse
    {
        $document = FpaDocument::query()->findOrFail($id);

        if (! SdiConfiguration::query()->where('tenant_id', $document->tenant_id)->value('sandbox_simulator_enabled')) {
            return $this->problemDetailsResponse(title: 'sandbox_disabled', detail: 'The sandbox simulator is disabled.', status: Response::HTTP_FORBIDDEN);
        }

        $this->simulator->simulate(
            $document,
            SdiNotificationType::from((string) $request->validated('type')),
            $request->validated('outcome'),
            $request->validated('error_code'),
            $request->validated('error_description'),
        );

        return $this->successResponse($this->present($document->refresh()), 'Receipt applied');
    }

    #[OA\Post(path: '/api/v1/sdi/invoices/{id}/preflight', operationId: 'preflightSdiInvoice', summary: 'Generate the XML and run XSD and Sogei validation', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['SDI'], responses: [new OA\Response(response: 200, description: 'Validation result')])]
    public function preflight(string $id): JsonResponse
    {
        $invoice = Invoice::query()->findOrFail($id);
        $this->authorize('sendSdi', $invoice);

        $xml = $this->builder->buildFatturaPaXml($invoice, '00000');
        $result = $this->sogei->validate($xml, (string) $invoice->tenant_id);

        foreach ($this->schema->errors($xml) as $message) {
            $result->error('XSD', '', ['message' => $message]);
        }

        return $this->successResponse(['xml' => $xml, ...$result->toArray()], 'Pre-flight completed');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(FpaDocument $d): array
    {
        return [
            'id' => $d->id,
            'invoice_id' => $d->invoice_id,
            'parent_fpa_document_id' => $d->parent_fpa_document_id,
            'direction' => $d->direction->value,
            'format_transmission' => $d->format_transmission,
            'document_type' => $d->document_type,
            'document_number' => $d->document_number,
            'document_date' => $d->document_date?->toDateString(),
            'sdi_identifier' => $d->sdi_identifier,
            'file_name' => $d->file_name,
            'transmission_progressive' => $d->transmission_progressive,
            'status' => $d->status->value,
            'validation_errors' => $d->validation_errors,
            'transmitted_at' => $d->transmitted_at?->toIso8601String(),
            'rejected_at' => $d->rejected_at?->toIso8601String(),
            'resubmission_deadline_at' => $d->resubmission_deadline_at?->toIso8601String(),
        ];
    }
}
