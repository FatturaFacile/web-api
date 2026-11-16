<?php

declare(strict_types=1);

namespace Modules\SupplierPortal\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\PortalAccessToken;
use App\Services\Portal\PortalTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Procurement\Models\PurchaseOrderLine;
use Modules\Procurement\Models\Supplier;
use Modules\SupplierPortal\Enums\OnboardingStatus;
use Modules\SupplierPortal\Http\Requests\BankChangeSubmitRequest;
use Modules\SupplierPortal\Http\Requests\PortalDocumentRequest;
use Modules\SupplierPortal\Http\Requests\PortalQuoteRequest;
use Modules\SupplierPortal\Http\Requests\RegistrationRequest;
use Modules\SupplierPortal\Http\Resources\DocumentSubmissionResource;
use Modules\SupplierPortal\Models\BankChangeRequest;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;
use Modules\SupplierPortal\Models\SupplierOnboarding;
use Modules\SupplierPortal\Services\BankChangeService;
use Modules\SupplierPortal\Services\SupplierDocumentService;
use Modules\SupplierPortal\Services\SupplierOnboardingService;
use Modules\SupplierPortal\Services\SupplierSelfService;
use OpenApi\Attributes as OA;

/**
 * What a supplier sees and does on its portal: its own records only, never the risk flag, the internal notes, the quotes
 * of other suppliers or the cost centres. The tenant and the supplier come from the personal token, never from the
 * request.
 */
final class SupplierPortalController extends Controller
{
    public function __construct(
        private readonly SupplierSelfService $self,
        private readonly SupplierOnboardingService $onboardings,
        private readonly SupplierDocumentService $documents,
        private readonly BankChangeService $bankChanges,
        private readonly PortalTokenService $tokens,
    ) {}

    #[OA\Get(path: '/api/v1/supplier-self-service/me', operationId: 'supplierPortalMe', description: 'The supplier, the status of its onboarding and the checklist of documents required from it, each with the status of the latest document sent.', summary: 'My profile and onboarding', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 200, description: 'Profile', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
    ])]
    public function me(Request $request): JsonResponse
    {
        $supplier = $this->supplier($request);
        $onboarding = $this->self->onboardingOf($supplier);
        $latest = SupplierDocumentSubmission::query()->where('supplier_id', $supplier->id)->orderByDesc('created_at')->get()->groupBy('requirement_id')->map(static fn ($group) => $group->first());

        return response()->json(['data' => [
            'supplier' => ['id' => (string) $supplier->id, 'company_name' => (string) $supplier->company_name, 'vat_number' => $supplier->vat_number, 'qualification_status' => (string) $supplier->qualification_status],
            'onboarding' => $onboarding === null ? null : [
                'status' => (string) $onboarding->status,
                'country' => (string) $onboarding->country,
                'status_reason' => in_array($onboarding->status, ['documents_required', 'rejected', 'blocked'], true) ? $onboarding->status_reason : null,
                'registration' => $onboarding->registration,
                'can_edit' => $onboarding->statusEnum()->acceptsSupplierInput(),
            ],
            'requirements' => $onboarding === null ? [] : $this->documents->requirementsFor((string) $onboarding->country)->map(static fn (DocumentRequirement $requirement): array => [
                'id' => (string) $requirement->id,
                'code' => (string) $requirement->code,
                'name' => (string) $requirement->name,
                'is_mandatory' => (bool) $requirement->is_mandatory,
                'requires_expiry' => (bool) $requirement->requires_expiry,
                'submission' => $latest->has($requirement->id) ? (new DocumentSubmissionResource($latest->get($requirement->id)))->resolve() : null,
            ])->values()->all(),
        ]]);
    }

    #[OA\Post(path: '/api/v1/supplier-self-service/registration', operationId: 'supplierPortalRegistration', description: 'Sends or corrects the registration data while the onboarding accepts input. The VAT number (Italian partita IVA) or UID (Swiss CHE number) is checked for format and check digit. The first submission moves the onboarding from invited to registration submitted.', summary: 'Send the registration data', security: [['supplierToken' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpRegistrationRequest')), tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 200, description: 'Registration stored', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function register(RegistrationRequest $request): JsonResponse
    {
        $onboarding = $this->onboarding($request);
        $updated = $this->onboardings->submitRegistration($onboarding, $request->validated());
        $this->log($onboarding, 'supplier_registration_submitted');

        return response()->json(['data' => ['status' => (string) $updated->status, 'country' => (string) $updated->country]]);
    }

    #[OA\Post(path: '/api/v1/supplier-self-service/documents', operationId: 'supplierPortalDocument', description: 'Sends a document (PDF, JPG or PNG) for a required type, with its reference and dates. It waits for the review; an earlier one still waiting is replaced.', summary: 'Send a document', security: [['supplierToken' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\MediaType(mediaType: 'multipart/form-data', schema: new OA\Schema(ref: '#/components/schemas/SpPortalDocumentRequest'))), tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 201, description: 'Document received', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/SpDocumentSubmission')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function uploadDocument(PortalDocumentRequest $request): JsonResponse
    {
        $supplier = $this->supplier($request);
        $onboarding = $this->onboarding($request);

        if (! $onboarding->statusEnum()->acceptsSupplierInput() && $onboarding->statusEnum() !== OnboardingStatus::Approved) {
            throw new BusinessRuleException('Documents cannot be sent in status '.$onboarding->status.'.');
        }

        $requirement = DocumentRequirement::query()->findOrFail($request->validated('requirement_id'));
        $submission = $this->documents->submit($supplier, $requirement, $request->validated(), $request->file('file'), 'portal', null, (string) $onboarding->id, (string) $onboarding->country);
        $this->log($onboarding, 'supplier_document_sent', $submission->id);

        return (new DocumentSubmissionResource($submission->load('requirement')))->response()->setStatusCode(201);
    }

    #[OA\Post(path: '/api/v1/supplier-self-service/submit-for-review', operationId: 'supplierPortalSubmitForReview', description: 'The supplier says it sent everything. Every mandatory document must have been sent; the onboarding then goes under review.', summary: 'Submit for review', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 200, description: 'Submitted', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function submitForReview(Request $request): JsonResponse
    {
        $onboarding = $this->onboarding($request);
        $updated = $this->onboardings->submitForReview($onboarding);
        $this->log($onboarding, 'supplier_submitted_for_review');

        return response()->json(['data' => ['status' => (string) $updated->status]]);
    }

    #[OA\Get(path: '/api/v1/supplier-self-service/purchase-orders', operationId: 'supplierPortalPurchaseOrders', description: 'The purchase orders sent to the supplier, newest first. Drafts and orders still in approval are never shown.', summary: 'My purchase orders', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 200, description: 'Orders', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
    ])]
    public function purchaseOrders(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->self->purchaseOrders($this->supplier($request))->map(static fn ($order): array => [
            'id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'date' => $order->date?->toDateString(),
            'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
            'currency' => (string) $order->currency,
            'total_net' => $order->total_net,
            'total_gross' => $order->total_gross,
        ])->values()]);
    }

    #[OA\Get(path: '/api/v1/supplier-self-service/purchase-orders/{id}', operationId: 'supplierPortalPurchaseOrder', description: 'A purchase order of the supplier with its lines and the quantities received so far.', summary: 'My purchase order', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Order', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function purchaseOrder(Request $request, string $id): JsonResponse
    {
        $order = $this->self->purchaseOrder($this->supplier($request), $id);

        return response()->json(['data' => [
            'id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'status' => (string) $order->status,
            'date' => $order->date?->toDateString(),
            'expected_delivery_date' => $order->expected_delivery_date?->toDateString(),
            'currency' => (string) $order->currency,
            'total_net' => $order->total_net,
            'total_tax' => $order->total_tax,
            'total_gross' => $order->total_gross,
            'payment_terms_code' => $order->payment_terms_code,
            'lines' => $this->self->orderLines($order)->map(static fn (PurchaseOrderLine $line): array => [
                'line_number' => (int) $line->line_number,
                'description' => (string) $line->description,
                'uom' => (string) $line->uom,
                'quantity_ordered' => (string) $line->quantity_ordered,
                'quantity_received' => (string) $line->quantity_received,
                'unit_price' => (string) $line->unit_price,
                'tax_rate' => (string) $line->tax_rate,
                'line_net_amount' => $line->line_net_amount,
                'expected_delivery_date' => $line->expected_delivery_date?->toDateString(),
            ])->values()->all(),
        ]]);
    }

    #[OA\Get(path: '/api/v1/supplier-self-service/rfqs', operationId: 'supplierPortalRfqs', description: 'The requests for quotation the supplier was invited to, newest deadline first, with whether it can still quote.', summary: 'My requests for quotation', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
    ])]
    public function rfqs(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->self->rfqs($this->supplier($request))->values()]);
    }

    #[OA\Get(path: '/api/v1/supplier-self-service/rfqs/{id}', operationId: 'supplierPortalRfq', description: 'A request the supplier was invited to, with its lines and the quote the supplier already sent. The quotes of the other suppliers are never shown.', summary: 'My request for quotation', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
    ])]
    public function rfq(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->self->rfq($this->supplier($request), $id)]);
    }

    #[OA\Post(path: '/api/v1/supplier-self-service/rfqs/{id}/quote', operationId: 'supplierPortalSubmitQuote', description: 'Submits the quote of the supplier, once, until the response deadline. The total is computed from quantity and unit price in exact decimals; the quantity defaults to the requested one. A rejected or blocked supplier cannot quote.', summary: 'Submit a quote', security: [['supplierToken' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpPortalQuoteRequest')), tags: ['Supplier Portal Area'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 201, description: 'Quote received', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function submitQuote(PortalQuoteRequest $request, string $id): JsonResponse
    {
        $supplier = $this->supplier($request);

        return response()->json(['data' => $this->self->submitQuote($supplier, $id, $request->validated())], 201);
    }

    #[OA\Post(path: '/api/v1/supplier-self-service/rfqs/{id}/decline', operationId: 'supplierPortalDeclineRfq', description: 'The supplier declines to quote on an open request it was invited to.', summary: 'Decline to quote', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], parameters: [new OA\Parameter(ref: '#/components/parameters/SupplierPortalId')], responses: [
        new OA\Response(response: 200, description: 'Declined', content: new OA\JsonContent(properties: [new OA\Property(property: 'declined', type: 'boolean')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalNotFound', response: 404),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function decline(Request $request, string $id): JsonResponse
    {
        $this->self->decline($this->supplier($request), $id);

        return response()->json(['declined' => true]);
    }

    #[OA\Get(path: '/api/v1/supplier-self-service/invoices', operationId: 'supplierPortalInvoices', description: 'The invoices of the supplier with their processing status (received, in approval, approved, on hold, rejected) and payment status.', summary: 'My invoices', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 200, description: 'Invoices', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
    ])]
    public function invoices(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->self->invoices($this->supplier($request))->values()]);
    }

    #[OA\Get(path: '/api/v1/supplier-self-service/bank-changes', operationId: 'supplierPortalBankChanges', description: 'The bank detail change requests of the supplier with their status. The IBAN is shown masked.', summary: 'My bank change requests', security: [['supplierToken' => []]], tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
    ])]
    public function bankChanges(Request $request): JsonResponse
    {
        $supplier = $this->supplier($request);

        return response()->json(['data' => BankChangeRequest::query()->where('supplier_id', $supplier->id)->orderByDesc('created_at')->get()->map(static fn (BankChangeRequest $change): array => [
            'id' => (string) $change->id,
            'status' => (string) $change->status,
            'account_holder' => (string) $change->account_holder,
            'iban_masked' => substr((string) $change->iban, 0, 4).str_repeat('*', max(0, strlen((string) $change->iban) - 8)).substr((string) $change->iban, -4),
            'created_at' => $change->created_at?->toIso8601String(),
            'rejection_reason' => $change->status === BankChangeRequest::Rejected ? $change->rejection_reason : null,
        ])->values()]);
    }

    #[OA\Post(path: '/api/v1/supplier-self-service/bank-changes', operationId: 'supplierPortalRequestBankChange', description: 'Asks to change the bank details of the supplier. Nothing changes until the buyer verifies it by phone and two different people approve it. The IBAN is validated.', summary: 'Request a bank detail change', security: [['supplierToken' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/SpBankChangeRequest')), tags: ['Supplier Portal Area'], responses: [
        new OA\Response(response: 201, description: 'Request received', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/SupplierPortalUnprocessable', response: 422),
    ])]
    public function requestBankChange(BankChangeSubmitRequest $request): JsonResponse
    {
        $supplier = $this->supplier($request);
        $change = $this->bankChanges->request($supplier, $request->validated(), 'portal', null);
        $onboarding = $this->self->onboardingOf($supplier);

        if ($onboarding !== null) {
            $this->log($onboarding, 'supplier_bank_change_requested', $change->id);
        }

        return response()->json(['data' => ['id' => (string) $change->id, 'status' => (string) $change->status, 'account_holder' => (string) $change->account_holder, 'created_at' => $change->created_at?->toIso8601String()]], 201);
    }

    private function supplier(Request $request): Supplier
    {
        return $request->attributes->get('portalSupplier');
    }

    private function onboarding(Request $request): SupplierOnboarding
    {
        return $this->self->onboardingOf($this->supplier($request)) ?? abort(404, 'There is no onboarding for this supplier.');
    }

    /**
     * Activity of the supplier on the portal, on the audit log of the portal links.
     */
    private function log(SupplierOnboarding $onboarding, string $action, ?string $subjectId = null): void
    {
        $access = PortalAccessToken::query()
            ->where('token_type', 'supplier_portal')
            ->where('scope_id', $onboarding->supplier_id)
            ->whereNull('revoked_at')
            ->latest('created_at')
            ->first();

        if ($access !== null) {
            $this->tokens->logActivity($access, $action, 'supplier', $subjectId ?? (string) $onboarding->supplier_id);
        }
    }
}
