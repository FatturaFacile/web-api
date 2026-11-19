<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Cpq;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cpq\CreateSignatureLinkRequest;
use App\Http\Requests\Cpq\DecideQuoteApprovalRequest;
use App\Http\Requests\Cpq\QuotePdfPreviewRequest;
use App\Http\Requests\Cpq\SimulateQuoteRequest;
use App\Http\Resources\Cpq\QuoteApprovalRequestResource;
use App\Http\Resources\Cpq\QuoteSignatureRequestResource;
use App\Http\Resources\QuoteResource;
use App\Http\Responses\ApiResponse;
use App\Models\Quote;
use App\Services\Pdf\DocumentPdfService;
use App\Services\Sales\Cpq\MarginCalculator;
use App\Services\Sales\Cpq\QuoteApprovalEvaluator;
use App\Services\Sales\Cpq\QuoteMarginSimulator;
use App\Services\Sales\Cpq\QuoteRevisionDiffService;
use App\Services\Sales\Cpq\QuoteSignatureService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

#[OA\Tag(name: 'Quote CPQ', description: 'Margin and discount simulation, revision comparison, tiered approval, signature links and PDF preview of sales quotes')]
class QuoteCpqController extends Controller
{
    use ApiResponse;
    use AuthorizesRequests;

    public function __construct(
        private readonly MarginCalculator $calculator,
        private readonly QuoteMarginSimulator $simulator,
        private readonly QuoteRevisionDiffService $diffs,
        private readonly QuoteApprovalEvaluator $approvals,
        private readonly QuoteSignatureService $signatures,
        private readonly DocumentPdfService $pdf,
    ) {}

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/margin',
        operationId: 'getSalesQuoteMargin',
        summary: 'Quote cost and margin',
        description: 'Industrial cost, list and net price and gross operating margin (amount and percentage) per line and for the quote. Reserved to the roles that see costs.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Figures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function margin(string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('viewCost', $quote);

        return $this->successResponse($this->calculator->calculate($this->simulator->lines($quote)));
    }

    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/simulate',
        operationId: 'simulateSalesQuoteMargin',
        summary: 'Simulate discount and margin',
        description: 'What-if on the quote, never saved: the global discount that reaches a target margin percentage or a target total price, or the effect of a discount percentage. Reserved to the roles that see costs.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'mode', type: 'string', enum: ['discount_percent', 'target_margin_percent', 'target_total_price']),
            new OA\Property(property: 'value', type: 'number'),
            new OA\Property(property: 'surcharge_amount', type: 'number', nullable: true),
            new OA\Property(property: 'setup_cost', type: 'number', nullable: true),
            new OA\Property(property: 'cost_exchange_rate', type: 'number', nullable: true),
        ])),
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Current and simulated figures', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function simulate(SimulateQuoteRequest $request, string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('viewCost', $quote);

        /** @var array{mode: string, value: mixed, surcharge_amount?: mixed, setup_cost?: mixed, cost_exchange_rate?: mixed} $input */
        $input = $request->validated();

        return $this->successResponse($this->simulator->simulate($quote, $input));
    }

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/revisions',
        operationId: 'listSalesQuoteRevisions',
        summary: 'Revision history',
        description: 'Every revision of the quote (Rev 0, 1, 2...), oldest first, with the revision notes and which one is current.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Revisions', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function revisions(string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('view', $quote);

        $revisions = Quote::query()
            ->where('quote_group_uuid', $quote->quote_group_uuid ?? $quote->id)
            ->orderBy('revision_number')
            ->get()
            ->map(static fn (Quote $revision): array => [
                'id' => $revision->id,
                'quote_number' => $revision->quote_number,
                'revision_number' => (int) ($revision->getAttribute('revision_number') ?? 0),
                'status' => $revision->status,
                'is_current_revision' => $revision->is_current_revision,
                'revision_notes' => $revision->revision_notes,
                'total_net' => $revision->total_net,
                'total_gross' => $revision->total_gross,
                'created_at' => $revision->created_at?->toIso8601String(),
            ])->values()->all();

        return $this->successResponse($revisions);
    }

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/diff/{otherId}',
        operationId: 'diffSalesQuoteRevisions',
        summary: 'Compare two revisions',
        description: 'Lines added, removed and modified between two revisions of the same quote, with the effect on totals and margin. Cost and margin deltas are hidden from the roles that do not see costs.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId'), new OA\Parameter(name: 'otherId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Differences', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function diff(string $id, string $otherId): JsonResponse
    {
        $from = $this->quote($id);
        $to = $this->quote($otherId);
        $this->authorize('view', $from);
        $this->authorize('view', $to);

        $result = $this->diffs->diff($from, $to);

        if (! request()->user()?->can('viewCost', $from)) {
            $result['totals'] = array_diff_key($result['totals'], array_flip(['cost_total', 'margin_amount', 'margin_percent']));
            foreach (['modified'] as $bucket) {
                $result['items'][$bucket] = array_map(static function (array $row): array {
                    $row['changes'] = array_diff_key($row['changes'], array_flip(['cost_price', 'labor_cost']));

                    return $row;
                }, $result['items'][$bucket]);
            }
        }

        return $this->successResponse($result);
    }

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/approvals',
        operationId: 'listSalesQuoteApprovals',
        summary: 'Approval status',
        description: 'The approval levels the quote needs against the thresholds of the company, with the decision of each level (who, when, why, which threshold was triggered).',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Evaluation and requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function approvals(string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('view', $quote);
        $evaluation = $this->approvals->evaluate($quote);

        if (! request()->user()?->can('viewCost', $quote)) {
            unset($evaluation['figures']);
        }

        return $this->successResponse([
            'evaluation' => $evaluation,
            'cleared' => $this->approvals->isCleared($quote),
            'requests' => QuoteApprovalRequestResource::collection($quote->approvalRequests)->resolve(),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/approvals/submit',
        operationId: 'submitSalesQuoteForTieredApproval',
        summary: 'Submit for tiered approval',
        description: 'Evaluates the discounts and the margin of the quote against the approval tiers. A quote that breaches a threshold becomes pending approval until every level approves; a quote within standard terms is left as it is.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Requests opened (empty when none needed)', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function submitApproval(Request $request, string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('update', $quote);
        $requests = $this->approvals->submit($quote, $request->user()?->id);

        return $this->successResponse(QuoteApprovalRequestResource::collection($requests)->resolve(), __('sales.cpq.submitted'));
    }

    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/approvals/approve',
        operationId: 'approveSalesQuoteTier',
        summary: 'Approve the next level',
        description: 'Approves the lowest pending approval level the caller can decide; the last approval approves the quote.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Decided request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function approve(DecideQuoteApprovalRequest $request, string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('decideApproval', $quote);
        $decided = $this->approvals->approve($quote, (string) $request->user()?->id, $this->role($request, $quote));

        return $this->successResponse(new QuoteApprovalRequestResource($decided), __('sales.cpq.approved'));
    }

    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/approvals/reject',
        operationId: 'rejectSalesQuoteTier',
        summary: 'Reject at the next level',
        description: 'Rejects the quote at the lowest pending level the caller can decide, with the reason; the other pending levels are closed.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [new OA\Property(property: 'rejection_reason', type: 'string')])),
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Decided request', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function reject(DecideQuoteApprovalRequest $request, string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('decideApproval', $quote);
        $decided = $this->approvals->reject($quote, (string) $request->user()?->id, $this->role($request, $quote), (string) $request->validated('rejection_reason'));

        return $this->successResponse(new QuoteApprovalRequestResource($decided), __('sales.cpq.rejected'));
    }

    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/signature-links',
        operationId: 'createSalesQuoteSignatureLink',
        summary: 'Create a signature link',
        description: 'Creates a secure, time-limited link for the customer to review the quote in a chosen language and sign it online. Refused while the quote needs an approval it has not got. The token is returned once.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
            new OA\Property(property: 'locale', type: 'string', enum: ['it', 'de', 'fr', 'en']),
            new OA\Property(property: 'validity_hours', type: 'integer', nullable: true),
        ])),
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 201, description: 'Link created', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function createSignatureLink(CreateSignatureLinkRequest $request, string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('update', $quote);
        $link = $this->signatures->createLink($quote, (string) $request->validated('locale'), (int) ($request->validated('validity_hours') ?? QuoteSignatureService::DefaultValidityHours), $request->user()?->id);

        return $this->successResponse([
            'request' => new QuoteSignatureRequestResource($link['request']),
            'token' => $link['token'],
            'url' => rtrim((string) config('app.frontend_url'), '/').'/quote-signature/'.$link['token'],
        ], __('sales.cpq.link_created'), HttpResponse::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/signature-requests',
        operationId: 'listSalesQuoteSignatureRequests',
        summary: 'Signature requests and audit',
        description: 'The signature links of the quote with their status and, once signed, the audit metadata (time, IP address, user agent, payload and PDF SHA-256).',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Requests', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function signatureRequests(string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('view', $quote);

        return $this->successResponse(QuoteSignatureRequestResource::collection($quote->signatureRequests()->latest()->get())->resolve());
    }

    #[OA\Post(
        path: '/api/v1/sales/quotes/{id}/signature-requests/{requestId}/revoke',
        operationId: 'revokeSalesQuoteSignatureLink',
        summary: 'Revoke a signature link',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId'), new OA\Parameter(name: 'requestId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Revoked', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleForbidden', response: 403),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function revokeSignatureLink(string $id, string $requestId): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('update', $quote);
        $request = $quote->signatureRequests()->whereKey($requestId)->firstOrFail();

        return $this->successResponse(new QuoteSignatureRequestResource($this->signatures->revoke($request)), __('sales.cpq.link_revoked_done'));
    }

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/signature-requests/{requestId}/verify',
        operationId: 'verifySalesQuoteSignature',
        summary: 'Verify a signed quote',
        description: 'Recomputes the SHA-256 of the quote payload and compares it with the hash stored at signature: false when the quote changed after it was signed.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId'), new OA\Parameter(name: 'requestId', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Verification', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function verifySignature(string $id, string $requestId): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('view', $quote);
        $request = $quote->signatureRequests()->whereKey($requestId)->firstOrFail();

        return $this->successResponse($this->signatures->verify($request));
    }

    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/preview-pdf',
        operationId: 'previewSalesQuotePdf',
        summary: 'Preview the quote PDF',
        description: 'Renders the quote PDF in the chosen language (it, de, fr, en), with the unit breakdown or only the package price, for the live preview of the editor.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [
            new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId'),
            new OA\Parameter(name: 'locale', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['it', 'de', 'fr', 'en'])),
            new OA\Parameter(name: 'show_technical_details', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'PDF', content: new OA\MediaType(mediaType: 'application/pdf')),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/SalesCycleValidationError', response: 422),
        ]
    )]
    public function previewPdf(QuotePdfPreviewRequest $request, string $id): Response
    {
        $quote = $this->quote($id);
        $this->authorize('download', $quote);
        $rendered = $this->pdf->render($quote, [
            'locale' => (string) $request->validated('locale'),
            'show_technical_details' => $request->boolean('show_technical_details', true),
        ]);

        return response($rendered->content, HttpResponse::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$rendered->filename.'"',
        ]);
    }

    /**
     * The quote with its lines and the cost fields only for the roles that see them.
     */
    #[OA\Get(
        path: '/api/v1/sales/quotes/{id}/cpq',
        operationId: 'getSalesQuoteCpq',
        summary: 'Quote with CPQ data',
        description: 'The quote with its lines, configuration snapshots and optional lines; cost, labor cost and margin of the lines only for the roles that see costs.',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Quote CPQ'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/SalesCycleQuoteId')],
        responses: [
            new OA\Response(response: 200, description: 'Quote', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
            new OA\Response(ref: '#/components/responses/SalesCycleUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/SalesCycleNotFound', response: 404),
        ]
    )]
    public function show(string $id): JsonResponse
    {
        $quote = $this->quote($id);
        $this->authorize('view', $quote);

        return $this->successResponse(new QuoteResource($quote->load(['items', 'contact'])));
    }

    private function quote(string $id): Quote
    {
        return Quote::query()->findOrFail($id);
    }

    private function role(Request $request, Quote $quote): string
    {
        return (string) $request->user()?->getRoleInTenant((string) $quote->tenant_id);
    }
}
