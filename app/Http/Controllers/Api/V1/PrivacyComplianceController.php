<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Privacy\PrivacyDataBreach;
use App\Models\Privacy\PrivacyDataSubjectRequest;
use App\Models\Privacy\PrivacySensitiveAccessLog;
use App\Services\Privacy\GlobalPrivacyComplianceService;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Tenant-wide GDPR (EU 2016/679) and Swiss nLPD (RS 235.1) Compliance Controller.
 */
final class PrivacyComplianceController extends Controller
{
    public function __construct(
        private readonly GlobalPrivacyComplianceService $privacyService,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/privacy/processing-activities',
        operationId: 'listPrivacyProcessingActivities',
        description: 'Record of processing activities (RoPA, Art. 30 GDPR / Art. 12 nLPD) of the active company: what personal data is processed, why, on which legal basis, who receives it, cross-border transfers and retention.',
        summary: 'List processing activities',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Processing activities ordered by title', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyProcessingActivityListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function processingActivities(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return response()->json([
            'data' => $this->privacyService->listProcessingActivities($tenantId),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/processing-activities',
        operationId: 'storePrivacyProcessingActivity',
        description: 'Adds an entry to the record of processing activities of the company. The entry is created as version 1 with status active unless archived is requested.',
        summary: 'Register a processing activity',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyProcessingActivityRequest')),
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 201, description: 'Processing activity registered', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyProcessingActivityResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function storeProcessingActivity(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'purpose' => ['required', 'string', 'max:2000'],
            'legal_basis' => ['required', 'string', 'max:60'],
            'data_categories' => ['required', 'array', 'min:1'],
            'data_categories.*' => ['string', 'max:80'],
            'subject_categories' => ['required', 'array', 'min:1'],
            'subject_categories.*' => ['string', 'max:80'],
            'recipients' => ['nullable', 'array'],
            'cross_border_countries' => ['nullable', 'array'],
            'transfer_safeguards' => ['nullable', 'string', 'max:80'],
            'retention_period' => ['required', 'string', 'max:120'],
            'security_measures' => ['nullable', 'string', 'max:2000'],
            'status' => ['nullable', Rule::in(['active', 'archived'])],
        ]);

        $activity = $this->privacyService->storeProcessingActivity($tenantId, $validated);

        return response()->json([
            'message' => 'Processing activity registered in Art. 30 GDPR / Art. 12 nLPD RoPA',
            'data' => $activity,
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/subprocessors',
        operationId: 'listPrivacySubprocessors',
        description: 'Third parties that process personal data on behalf of the company, with the outcome of the Swiss nLPD adequacy check of their country and the safeguard in place.',
        summary: 'List subprocessors',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Subprocessors ordered by name', content: new OA\JsonContent(ref: '#/components/schemas/PrivacySubprocessorListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function subprocessors(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return response()->json([
            'data' => $this->privacyService->listSubprocessors($tenantId),
        ]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/subprocessors',
        operationId: 'storePrivacySubprocessor',
        description: 'Registers a subprocessor. The country is checked against the countries recognised as adequate by the Swiss Federal Council (Annex 1 DPOOP, Art. 16 nLPD); without an explicit safeguard, adequacy_decision is recorded for adequate countries and scc_fdpic (standard contractual clauses recognised by the FDPIC) for the others.',
        summary: 'Register a subprocessor',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacySubprocessorRequest')),
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 201, description: 'Subprocessor registered', content: new OA\JsonContent(ref: '#/components/schemas/PrivacySubprocessorResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function storeSubprocessor(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'service_type' => ['required', 'string', 'max:120'],
            'country_code' => ['required', 'string', 'size:2'],
            'safeguard_mechanism' => ['nullable', 'string', 'max:80'],
            'dpa_signed_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $subprocessor = $this->privacyService->storeSubprocessor($tenantId, $validated);

        return response()->json([
            'message' => 'Subprocessor registered with Swiss nLPD Art. 16 cross-border adequacy check',
            'data' => $subprocessor,
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/data-subject-requests',
        operationId: 'listPrivacyDataSubjectRequests',
        description: 'Requests of data subjects (access, portability, erasure, restriction, rectification) of the active company, newest first, with their statutory deadline.',
        summary: 'List data subject requests',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Data subject requests', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function dataSubjectRequests(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $requests = PrivacyDataSubjectRequest::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/data-subject-requests',
        operationId: 'storePrivacyDataSubjectRequest',
        description: 'Registers the request of a data subject. The request starts as received, handled by the current user, with a deadline of 30 days from now. Registering does not execute anything: use the portability export and execute-erasure endpoints.',
        summary: 'Register a data subject request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestRequest')),
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 201, description: 'Request registered', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function storeDataSubjectRequest(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $validated = $request->validate([
            'request_type' => ['required', Rule::in(['access', 'portability', 'erasure', 'restriction', 'rectification'])],
            'regulation' => ['nullable', Rule::in(['nLPD', 'GDPR', 'both'])],
            'subject_type' => ['required', Rule::in(['contact', 'employee'])],
            'subject_id' => ['required', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $dsr = $this->privacyService->createDataSubjectRequest(
            $tenantId,
            $validated,
            $request->user() ? (string) $request->user()->id : null
        );

        return response()->json([
            'message' => 'Data subject request registered',
            'data' => $dsr,
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/portability/{subjectType}/{subjectId}',
        operationId: 'exportPrivacySubjectDossier',
        description: 'Portable machine-readable dossier with all personal data of a contact or employee (GDPR Art. 15 and 20, nLPD Art. 25 and 28). The response contains personal data: the export is written to the sensitive access log with the user and IP address.',
        summary: 'Export a data subject dossier',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacySubjectType'), new OA\Parameter(ref: '#/components/parameters/PrivacySubjectId')],
        responses: [
            new OA\Response(response: 200, description: 'Dossier', content: new OA\JsonContent(ref: '#/components/schemas/PrivacySubjectDossierResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function exportSubjectDossier(Request $request, string $subjectType, string $subjectId): JsonResponse
    {
        if (! in_array($subjectType, ['contact', 'employee'], true)) {
            abort(422, 'Unsupported subject type.');
        }

        $tenantId = $this->tenantId($request);
        $dossier = $this->privacyService->exportSubjectDossier(
            $tenantId,
            $subjectType,
            $subjectId,
            $request->user() ? (string) $request->user()->id : null,
            $request->ip()
        );

        return response()->json(['data' => $dossier]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}/execute-erasure',
        operationId: 'executePrivacyErasure',
        description: 'Executes the erasure or restriction of processing for the subject of the request (GDPR Art. 17/18, nLPD Art. 32) in one transaction. Contact and employee contact details are wiped and processing is restricted. Names are anonymised only when no statutory retention applies (CO Art. 958f 10 years for accounting and payroll vouchers, ArG Art. 46 5 years for working time records); otherwise the request ends as partially_completed_legal_hold and the legal holds are listed. The call is logged in the sensitive access log. It is irreversible and has no request body.',
        summary: 'Execute erasure or restriction',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'Erasure executed; data holds status, legal_holds and erasure_summary', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function executeErasure(Request $request, string $id): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $dsr = PrivacyDataSubjectRequest::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($id)
            ->firstOrFail();

        $updated = $this->privacyService->executeErasureOrRestriction(
            $tenantId,
            $dsr,
            $request->user() ? (string) $request->user()->id : null
        );

        return response()->json([
            'message' => 'Erasure / restriction executed with statutory retention verification',
            'data' => $updated,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/breaches',
        operationId: 'listPrivacyBreaches',
        description: 'Register of personal data breaches of the active company, most recently discovered first, with the 72-hour notification deadline and the notification status.',
        summary: 'List personal data breaches',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Data breaches', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function breaches(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $breaches = PrivacyDataBreach::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('discovered_at')
            ->get();

        return response()->json(['data' => $breaches]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/breaches',
        operationId: 'storePrivacyBreach',
        description: 'Logs a personal data breach with a notification deadline of 72 hours from discovery (GDPR Art. 33, nLPD Art. 24). Severity medium, high or critical flags the authority notification as required; high and critical also the notification of the data subjects. Status starts as investigating.',
        summary: 'Report a personal data breach',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachRequest')),
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 201, description: 'Breach logged', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function storeBreach(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'severity' => ['required', Rule::in(['low', 'medium', 'high', 'critical'])],
            'discovered_at' => ['nullable', 'date'],
            'affected_records_count' => ['nullable', 'integer', 'min:0'],
            'data_categories' => ['required', 'array', 'min:1'],
            'data_categories.*' => ['string', 'max:80'],
            'likely_consequences' => ['nullable', 'string', 'max:2000'],
            'mitigation_measures' => ['nullable', 'string', 'max:2000'],
        ]);

        $breach = $this->privacyService->reportDataBreach($tenantId, $validated);

        return response()->json([
            'message' => 'Data breach logged with 72-hour FDPIC/GDPR notification deadline',
            'data' => $breach,
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/sensitive-access-logs',
        operationId: 'listPrivacySensitiveAccessLogs',
        description: 'Audit trail of reads and exports of sensitive personal data (Swiss DPOOP Art. 4): who accessed which subject, which data category, action and IP address. Returns only the latest 200 entries, newest first; there is no pagination or filter.',
        summary: 'List sensitive data access logs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Latest 200 access log lines', content: new OA\JsonContent(ref: '#/components/schemas/PrivacySensitiveAccessLogListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function sensitiveAccessLogs(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $logs = PrivacySensitiveAccessLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $logs]);
    }

    private function tenantId(Request $request): string
    {
        $tenantId = $this->tenantContext->getTenantId() ?? $request->user()?->tenant_id;
        if (! $tenantId) {
            abort(403, 'Missing or unresolvable tenant context');
        }

        return (string) $tenantId;
    }
}
