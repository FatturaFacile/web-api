<?php

declare(strict_types=1);

namespace Modules\CRM\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CRM\Http\Requests\StoreConsentRequest;
use Modules\CRM\Models\Consent;
use Modules\CRM\Services\ConsentService;
use OpenApi\Attributes as OA;

/**
 * GDPR consent register of contacts and leads: append-only history plus the effective state per channel and purpose.
 */
final class ConsentController extends Controller
{
    public function __construct(
        private readonly ConsentService $consentService,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * The consent history and effective state of a contact or a lead.
     */
    #[OA\Get(
        path: '/api/v1/crm/consents',
        operationId: 'listCrmConsents',
        description: 'Returns the consent history (newest first) and the effective state per channel and purpose of one contact or one lead of the active tenant.',
        summary: 'List consents',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        parameters: [
            new OA\Parameter(name: 'contact_id', in: 'query', required: false, description: 'Contact (or give lead_id)', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'lead_id', in: 'query', required: false, description: 'Lead (or give contact_id)', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Consents', content: new OA\JsonContent(properties: [new OA\Property(property: 'effective', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMConsentState')), new OA\Property(property: 'history', type: 'array', items: new OA\Items(ref: '#/components/schemas/CRMConsent'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'contact_id' => ['nullable', 'uuid', 'required_without:lead_id'],
            'lead_id' => ['nullable', 'uuid', 'required_without:contact_id'],
        ]);

        $contactId = $request->query('contact_id');
        $leadId = $contactId === null ? $request->query('lead_id') : null;

        $history = Consent::where('tenant_id', $this->tenantContext->getTenantId())
            ->when($contactId !== null, fn ($query) => $query->where('contact_id', $contactId), fn ($query) => $query->where('lead_id', $leadId))
            ->orderByDesc('captured_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'effective' => $this->consentService->summary(is_string($contactId) ? $contactId : null, is_string($leadId) ? $leadId : null),
            'history' => $history->map(fn (Consent $consent): array => $this->present($consent))->values(),
        ]);
    }

    /**
     * Records a grant or a withdrawal.
     */
    #[OA\Post(
        path: '/api/v1/crm/consents',
        operationId: 'recordCrmConsent',
        description: 'Records a consent grant or withdrawal of a contact or lead for a channel and purpose, with its source, capture time and evidence. Records are never edited: the latest one is the effective state. Campaign sending honours it.',
        summary: 'Record consent',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['CRM Marketing'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CRMStoreConsentRequest')),
        responses: [
            new OA\Response(response: 201, description: 'Consent recorded', content: new OA\JsonContent(ref: '#/components/schemas/CRMConsent')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Read-only role', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function store(StoreConsentRequest $request): JsonResponse
    {
        $consent = $this->consentService->record(
            (string) $this->tenantContext->getTenantId(),
            [...$request->validated(), 'ip_address' => $request->ip()],
            $request->user()?->id !== null ? (string) $request->user()->id : null,
        );

        return response()->json($this->present($consent), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Consent $consent): array
    {
        return [
            'id' => (string) $consent->id,
            'contact_id' => $consent->contact_id,
            'lead_id' => $consent->lead_id,
            'channel' => $consent->channel,
            'purpose' => $consent->purpose,
            'status' => $consent->status,
            'source' => $consent->source,
            'captured_at' => $consent->captured_at?->toIso8601String(),
            'evidence' => $consent->evidence,
            'recorded_by_user_id' => $consent->recorded_by_user_id,
            'created_at' => $consent->created_at?->toIso8601String(),
        ];
    }
}
