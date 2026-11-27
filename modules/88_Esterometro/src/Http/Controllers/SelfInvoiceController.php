<?php

declare(strict_types=1);

namespace Modules\Esterometro\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PassiveInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Esterometro\Domain\Enums\SelfInvoiceDocumentType;
use Modules\Esterometro\Domain\Enums\TransactionNature;
use Modules\Esterometro\Exceptions\EsterometroException;
use Modules\Esterometro\Http\Requests\BatchSendSelfInvoiceRequest;
use Modules\Esterometro\Http\Requests\ClassifyPreviewRequest;
use Modules\Esterometro\Http\Requests\StoreSelfInvoiceRequest;
use Modules\Esterometro\Http\Requests\UpdateSelfInvoiceRequest;
use Modules\Esterometro\Http\Resources\SelfInvoiceResource;
use Modules\Esterometro\Models\SelfInvoice;
use Modules\Esterometro\Services\SelfInvoiceClassifierService;
use Modules\Esterometro\Services\SelfInvoiceGeneratorService;
use Modules\Esterometro\Services\SelfInvoiceSdiDispatcherService;
use Modules\Esterometro\Services\SelfInvoiceVatReconciliationService;
use OpenApi\Attributes as OA;
use Throwable;

final class SelfInvoiceController extends Controller
{
    public function __construct(
        private readonly SelfInvoiceClassifierService $classifier,
        private readonly SelfInvoiceGeneratorService $generator,
        private readonly SelfInvoiceVatReconciliationService $vatReconciliation,
        private readonly SelfInvoiceSdiDispatcherService $dispatcher,
    ) {}

    #[OA\Get(
        path: '/api/v1/esterometro/self-invoices',
        summary: 'List TD16-TD28 self-invoices for the current tenant',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Paginated self-invoices')],
    )]
    public function index(Request $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;

        $query = SelfInvoice::query()
            ->where('tenant_id', $tenantId)
            ->with('lines')
            ->when($request->filled('document_type'), static fn ($q) => $q->where('document_type', (string) $request->query('document_type')))
            ->when($request->filled('status'), static fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('sdi_status'), static fn ($q) => $q->where('sdi_status', (string) $request->query('sdi_status')))
            ->when($request->filled('passive_invoice_id'), static fn ($q) => $q->where('passive_invoice_id', (string) $request->query('passive_invoice_id')))
            ->when($request->filled('search'), static function ($q) use ($request): void {
                $term = '%'.strtolower(trim((string) $request->query('search'))).'%';
                $q->where(static function ($inner) use ($term): void {
                    $inner->whereRaw('LOWER(document_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(supplier_name) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(COALESCE(original_invoice_number, \'\')) LIKE ?', [$term]);
                });
            })
            ->orderByDesc('integration_date')
            ->orderByDesc('sequential_number');

        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $paginated = $query->paginate($perPage);

        return SelfInvoiceResource::collection($paginated)->response();
    }

    #[OA\Post(
        path: '/api/v1/esterometro/classify-preview',
        summary: 'Preview deterministic TD16-TD28 classification for a passive invoice or payload',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Classification result')],
    )]
    public function classifyPreview(ClassifyPreviewRequest $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $data = $request->validated();

        $overrideType = isset($data['override_document_type'])
            ? SelfInvoiceDocumentType::from((string) $data['override_document_type'])
            : null;
        $overrideNature = isset($data['transaction_nature'])
            ? TransactionNature::from((string) $data['transaction_nature'])
            : null;

        if (! empty($data['passive_invoice_id'])) {
            $passive = PassiveInvoice::query()
                ->where('tenant_id', $tenantId)
                ->findOrFail((string) $data['passive_invoice_id']);

            $result = $this->classifier->classifyPassiveInvoice(
                $passive,
                $overrideType,
                $overrideNature,
                $data['regularization_trigger'] ?? null,
            );
        } else {
            $result = $this->classifier->classify(
                tenantId: $tenantId,
                supplierCountryCode: (string) $data['supplier_country_code'],
                transactionNature: $overrideNature ?? TransactionNature::Services,
                sourceVatNature: $data['source_vat_nature'] ?? null,
                hasCustomsImportBill: (bool) ($data['has_customs_import_bill'] ?? false),
                supplierChargedItalianVat: (bool) ($data['supplier_charged_italian_vat'] ?? false),
                regularizationTrigger: $data['regularization_trigger'] ?? null,
                overrideType: $overrideType,
            );
        }

        if ($result['suggested_document_type'] instanceof SelfInvoiceDocumentType) {
            $result['suggested_document_type'] = $result['suggested_document_type']->value;
        }
        if ($result['document_type'] instanceof SelfInvoiceDocumentType) {
            $result['document_type'] = $result['document_type']->value;
        }

        return response()->json(['data' => $result]);
    }

    #[OA\Post(
        path: '/api/v1/esterometro/self-invoices',
        summary: 'Create a manual or TD20 regularization self-invoice',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 201, description: 'Self-invoice created')],
    )]
    public function store(StoreSelfInvoiceRequest $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $userId = (string) $request->user()->id;

        $selfInvoice = $this->generator->createManual($tenantId, $userId, $request->validated());

        return (new SelfInvoiceResource($selfInvoice))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Post(
        path: '/api/v1/esterometro/self-invoices/from-passive/{passiveInvoiceId}',
        summary: 'Generate a TD16-TD28 self-invoice from an existing passive invoice',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 201, description: 'Self-invoice generated')],
    )]
    public function generateFromPassive(Request $request, string $passiveInvoiceId): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $userId = (string) $request->user()->id;

        $passive = PassiveInvoice::query()
            ->where('tenant_id', $tenantId)
            ->findOrFail($passiveInvoiceId);

        $selfInvoice = $this->generator->generateFromPassiveInvoice($passive, $userId, $request->all());

        return (new SelfInvoiceResource($selfInvoice))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Get(
        path: '/api/v1/esterometro/self-invoices/{id}',
        summary: 'Show self-invoice details including lines, XML, and SDI logs',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Self-invoice details')],
    )]
    public function show(Request $request, string $id): JsonResponse
    {
        $selfInvoice = $this->findForTenant($request, $id);
        $selfInvoice->load(['lines', 'sdiLogs']);

        return (new SelfInvoiceResource($selfInvoice))->response();
    }

    #[OA\Put(
        path: '/api/v1/esterometro/self-invoices/{id}',
        summary: 'Update a draft or rejected self-invoice and rebuild XML',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Self-invoice updated')],
    )]
    public function update(UpdateSelfInvoiceRequest $request, string $id): JsonResponse
    {
        $selfInvoice = $this->findForTenant($request, $id);
        $updated = $this->generator->updateAndRebuild($selfInvoice, (string) $request->user()->id, $request->validated());

        return (new SelfInvoiceResource($updated))->response();
    }

    #[OA\Post(
        path: '/api/v1/esterometro/self-invoices/{id}/validate',
        summary: 'Build and validate FatturaPA XML against XSD and SOGEI congruity rules',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Self-invoice validated')],
    )]
    public function validateXml(Request $request, string $id): JsonResponse
    {
        $selfInvoice = $this->findForTenant($request, $id);
        $validated = $this->generator->validateAndBuildXml(
            $selfInvoice,
            (string) $request->user()->id,
            $request->boolean('reconcile_vat', true),
        );

        return (new SelfInvoiceResource($validated))->response();
    }

    #[OA\Post(
        path: '/api/v1/esterometro/self-invoices/{id}/reconcile-vat',
        summary: 'Post balanced entries to VEN-AUT, ACQ-RC VAT registers and Prima Nota',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'VAT registers reconciled')],
    )]
    public function reconcileVat(Request $request, string $id): JsonResponse
    {
        $selfInvoice = $this->findForTenant($request, $id);
        $reconciled = $this->vatReconciliation->reconcile($selfInvoice, (string) $request->user()->id);

        return (new SelfInvoiceResource($reconciled))->response();
    }

    #[OA\Post(
        path: '/api/v1/esterometro/self-invoices/{id}/send-sdi',
        summary: 'Sign and transmit a self-invoice to SDI',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Sent to SDI')],
    )]
    public function sendToSdi(Request $request, string $id): JsonResponse
    {
        $selfInvoice = $this->findForTenant($request, $id);
        $sent = $this->dispatcher->dispatch($selfInvoice, (string) $request->user()->id);

        return (new SelfInvoiceResource($sent))->response();
    }

    #[OA\Post(
        path: '/api/v1/esterometro/self-invoices/batch-send',
        summary: 'Batch transmit multiple self-invoices to SDI',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Batch dispatch results')],
    )]
    public function batchSend(BatchSendSelfInvoiceRequest $request): JsonResponse
    {
        $tenantId = (string) $request->user()->tenant_id;
        $userId = (string) $request->user()->id;
        $ids = $request->validated()['ids'];

        $sent = [];
        $failed = [];

        foreach ($ids as $id) {
            $selfInvoice = SelfInvoice::query()->where('tenant_id', $tenantId)->find($id);
            if ($selfInvoice === null) {
                $failed[] = ['id' => $id, 'error' => 'Not found'];
                continue;
            }

            try {
                $dispatched = $this->dispatcher->dispatch($selfInvoice, $userId);
                $sent[] = $dispatched->id;
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'error' => $e->getMessage()];
            }
        }

        return response()->json([
            'data' => [
                'sent_ids' => $sent,
                'failed' => $failed,
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/esterometro/self-invoices/{id}/sdi-notification',
        summary: 'Record an SDI receipt notification (RC or NS)',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'Notification processed')],
    )]
    public function recordSdiNotification(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'notification_type' => ['required', 'string', 'in:RC,NS'],
            'sdi_identificativo' => ['nullable', 'string', 'max:64'],
            'error_code' => ['nullable', 'string', 'max:30'],
            'error_description' => ['nullable', 'string', 'max:2000'],
            'raw_payload' => ['nullable', 'string'],
        ]);

        $selfInvoice = $this->findForTenant($request, $id);
        $updated = $this->dispatcher->handleNotification(
            selfInvoice: $selfInvoice,
            notificationType: (string) $request->input('notification_type'),
            sdiIdentificativo: $request->input('sdi_identificativo'),
            errorCode: $request->input('error_code'),
            errorDescription: $request->input('error_description'),
            rawPayload: $request->input('raw_payload'),
        );

        return (new SelfInvoiceResource($updated))->response();
    }

    #[OA\Get(
        path: '/api/v1/esterometro/self-invoices/{id}/xml',
        summary: 'Download FatturaPA XML for a self-invoice',
        tags: ['Esterometro'],
        responses: [new OA\Response(response: 200, description: 'FatturaPA XML')],
    )]
    public function downloadXml(Request $request, string $id): Response
    {
        $selfInvoice = $this->findForTenant($request, $id);
        $xml = $selfInvoice->xml_content;
        if ($xml === null || $xml === '') {
            throw EsterometroException::xmlValidationFailed('XML not yet generated.');
        }

        $filename = ($selfInvoice->sdi_filename ?: $selfInvoice->document_number.'.xml');

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function findForTenant(Request $request, string $id): SelfInvoice
    {
        return SelfInvoice::query()
            ->where('tenant_id', (string) $request->user()->tenant_id)
            ->findOrFail($id);
    }
}
