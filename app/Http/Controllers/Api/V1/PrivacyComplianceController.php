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

/**
 * Tenant-wide GDPR (EU 2016/679) and Swiss nLPD (RS 235.1) Compliance Controller.
 */
final class PrivacyComplianceController extends Controller
{
    public function __construct(
        private readonly GlobalPrivacyComplianceService $privacyService,
        private readonly TenantContext $tenantContext,
    ) {}

    public function processingActivities(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return response()->json([
            'data' => $this->privacyService->listProcessingActivities($tenantId),
        ]);
    }

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

    public function subprocessors(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);

        return response()->json([
            'data' => $this->privacyService->listSubprocessors($tenantId),
        ]);
    }

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

    public function dataSubjectRequests(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $requests = PrivacyDataSubjectRequest::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $requests]);
    }

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

    public function breaches(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $breaches = PrivacyDataBreach::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->orderByDesc('discovered_at')
            ->get();

        return response()->json(['data' => $breaches]);
    }

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
