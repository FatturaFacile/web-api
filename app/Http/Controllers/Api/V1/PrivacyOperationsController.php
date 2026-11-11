<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Privacy\PrivacyDataBreach;
use App\Models\Privacy\PrivacyDataSubjectRequest;
use App\Models\Privacy\PrivacyRetentionPolicy;
use App\Models\Privacy\PrivacyRetentionRun;
use App\Services\Privacy\DataBreachService;
use App\Services\Privacy\DataSubjectRequestWorkflow;
use App\Services\Privacy\PersonalDataMapService;
use App\Services\Privacy\PersonalDataRegistry;
use App\Services\Privacy\PrivacyRetentionService;
use App\Services\Privacy\PrivacySettings;
use App\Services\Privacy\RetentionSuggestions;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Operational GDPR (EU 2016/679) and Swiss nLPD (RS 235.1): workflow of the data subject requests, map of the personal
 * data, export, privacy settings, retention policies and the operations on the breach register. Same access rules as
 * PrivacyComplianceController: the system settings module, owners and administrators.
 */
final class PrivacyOperationsController extends Controller
{
    public function __construct(
        private readonly DataSubjectRequestWorkflow $workflow,
        private readonly DataBreachService $breaches,
        private readonly PersonalDataMapService $dataMap,
        private readonly PrivacyRetentionService $retention,
        private readonly RetentionSuggestions $suggestions,
        private readonly PrivacySettings $settings,
        private readonly PersonalDataRegistry $registry,
        private readonly TenantContext $tenantContext,
    ) {}

    #[OA\Get(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}',
        operationId: 'showPrivacyDataSubjectRequest',
        description: 'A data subject request with its history of steps (who moved it, when, from which status to which).',
        summary: 'Show a data subject request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'The request with its events', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestDetailResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function showRequest(Request $request, string $id): JsonResponse
    {
        $dsr = $this->workflow->find($this->tenantId($request), $id);
        $dsr->load('events');

        return response()->json(['data' => $dsr]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}/verify-identity',
        operationId: 'verifyPrivacyDataSubjectRequestIdentity',
        description: 'Records that the identity of the requester was verified (received to identity_verified). Nothing is exported or erased before this step.',
        summary: 'Verify the identity of the requester',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyVerifyIdentityRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'Request moved to identity_verified', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function verifyIdentity(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'method' => ['required', 'string', 'max:60'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $dsr = $this->workflow->find($this->tenantId($request), $id);

        return $this->done('Identity verified', fn (): PrivacyDataSubjectRequest => $this->workflow->verifyIdentity($dsr, $validated['method'], $this->actorId($request), $validated['note'] ?? null));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}/start',
        operationId: 'startPrivacyDataSubjectRequest',
        description: 'Starts the work on a request whose identity was verified (identity_verified to in_progress).',
        summary: 'Start a data subject request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'Request moved to in_progress', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function start(Request $request, string $id): JsonResponse
    {
        $dsr = $this->workflow->find($this->tenantId($request), $id);

        return $this->done('Request started', fn (): PrivacyDataSubjectRequest => $this->workflow->start($dsr, $this->actorId($request)));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}/complete',
        operationId: 'completePrivacyDataSubjectRequest',
        description: 'Completes a request in progress. Erasure runs the erasure with the statutory retention check (accounting, payroll and evidence records are never deleted; the response lists them with the reason). Restriction marks the data as restricted. An objection to marketing withdraws the granted consents. Access and portability need the export first. Rectification and other objections need a written response_summary.',
        summary: 'Complete a data subject request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyCompleteRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'Request completed', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function complete(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['response_summary' => ['nullable', 'string', 'max:4000']]);
        $dsr = $this->workflow->find($this->tenantId($request), $id);

        return $this->done('Request completed', fn (): PrivacyDataSubjectRequest => $this->workflow->complete($dsr, $validated['response_summary'] ?? null, $this->actorId($request)));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}/reject',
        operationId: 'rejectPrivacyDataSubjectRequest',
        description: 'Rejects a request that is not closed, with the reason to communicate to the data subject (art. 12(4) GDPR).',
        summary: 'Reject a data subject request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRejectRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'Request rejected', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function reject(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $dsr = $this->workflow->find($this->tenantId($request), $id);

        return $this->done('Request rejected', fn (): PrivacyDataSubjectRequest => $this->workflow->reject($dsr, $validated['reason'], $this->actorId($request)));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}/extend',
        operationId: 'extendPrivacyDataSubjectRequest',
        description: 'Extends the deadline of an open request once, by at most 60 days, with the reason (art. 12(3) GDPR allows two further months for complex or numerous requests: to be confirmed). The data subject must be informed within the original deadline.',
        summary: 'Extend the deadline of a request',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyExtendRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'Deadline extended', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataSubjectRequestResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function extend(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['required', 'integer', 'min:1', 'max:'.PrivacySettings::MaxExtensionDays],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $dsr = $this->workflow->find($this->tenantId($request), $id);

        return $this->done('Deadline extended', fn (): PrivacyDataSubjectRequest => $this->workflow->extend($dsr, (int) $validated['days'], $validated['reason'], $this->actorId($request)));
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/data-subject-requests/{id}/export',
        operationId: 'exportPrivacyDataSubjectRequest',
        description: 'Zip with the data of the subject across the modules: export.json plus one CSV per table. For access and portability requests in progress (identity verified). It contains PERSONAL DATA: the access is written to the sensitive access log and the audit ledger. Technical secrets are never exported.',
        summary: 'Export the data of the subject (zip)',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyDataSubjectRequestId')],
        responses: [
            new OA\Response(response: 200, description: 'Zip archive', content: new OA\MediaType(mediaType: 'application/zip', schema: new OA\Schema(type: 'string', format: 'binary'))),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function export(Request $request, string $id): BinaryFileResponse
    {
        $dsr = $this->workflow->find($this->tenantId($request), $id);

        try {
            $path = $this->workflow->export($dsr, $this->actorId($request), $request->ip());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        return response()->download($path, "data-subject-export-{$dsr->id}.zip", ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/data-map',
        operationId: 'getPrivacyDataMap',
        description: 'Map of the personal data of the company built from the registry: every table with personal columns, the kind of subject, the retention class and the rows held. undeclared_personal_columns lists columns that look personal and are missing from the registry. With scan=1 the whole database is scanned for tables that look personal and are neither registered nor exempt (slower).',
        summary: 'Personal data map',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(name: 'scan', in: 'query', required: false, schema: new OA\Schema(type: 'boolean'))],
        responses: [
            new OA\Response(response: 200, description: 'Data map', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataMapResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function dataMap(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dataMap->build($this->tenantId($request), $request->boolean('scan'))]);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/settings',
        operationId: 'getPrivacySettings',
        description: 'Privacy settings of the company: the length of the period to answer a data subject request. The default of 30 days is TO BE CONFIRMED by the data protection officer or counsel.',
        summary: 'Get privacy settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(ref: '#/components/schemas/PrivacySettingsResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function showSettings(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->settings->toArray($this->tenantId($request))]);
    }

    #[OA\Put(
        path: '/api/v1/settings/privacy/settings',
        operationId: 'updatePrivacySettings',
        description: 'Sets the number of days (1 to 90) to answer a data subject request. Applies to requests registered afterwards; requests already open keep their deadline.',
        summary: 'Update privacy settings',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacySettingsRequest')),
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Settings updated', content: new OA\JsonContent(ref: '#/components/schemas/PrivacySettingsResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dsr_deadline_days' => ['required', 'integer', 'min:'.PrivacySettings::MinDeadlineDays, 'max:'.PrivacySettings::MaxDeadlineDays],
        ]);
        $tenantId = $this->tenantId($request);
        $this->settings->setDeadlineDays($tenantId, (int) $validated['dsr_deadline_days']);

        return response()->json(['message' => 'Privacy settings updated', 'data' => $this->settings->toArray($tenantId)]);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/retention-policies',
        operationId: 'listPrivacyRetentionPolicies',
        description: 'Retention policies of the company and the tables of the registry that can have one (erasable data with an age column). Accounting, payroll and evidence records never appear as available: their retention is fixed by law.',
        summary: 'List retention policies',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Policies and available tables', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionPolicyListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function retentionPolicies(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId($request);
        $policies = PrivacyRetentionPolicy::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->orderBy('entry_key')->get();

        $available = [];
        foreach ($this->registry->entries() as $entry) {
            if ($entry->supportsRetentionPolicy()) {
                $available[] = ['entry_key' => $entry->key(), 'label' => $entry->label, 'personal_columns' => $entry->personalColumns, 'age_column' => $entry->ageColumn];
            }
        }

        return response()->json(['data' => $policies, 'available_tables' => $available]);
    }

    #[OA\Put(
        path: '/api/v1/settings/privacy/retention-policies/{entryKey}',
        operationId: 'savePrivacyRetentionPolicy',
        description: 'Creates or updates the retention policy of a table: rows whose age column is older than retention_days are anonymised (never deleted) by the scheduled command privacy:apply-retention. Tables with a statutory retention are refused. The period is chosen by the company: no default is proposed.',
        summary: 'Save a retention policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionPolicyRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(name: 'entryKey', in: 'path', required: true, description: 'Table of the registry, for example crm_leads', schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 200, description: 'Policy saved', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionPolicyResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function savePolicy(Request $request, string $entryKey): JsonResponse
    {
        $validated = $request->validate([
            'retention_days' => ['required', 'integer', 'min:1', 'max:36500'],
            'is_active' => ['nullable', 'boolean'],
            'legal_basis_note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $policy = $this->retention->savePolicy($this->tenantId($request), $entryKey, (int) $validated['retention_days'], (bool) ($validated['is_active'] ?? true), $validated['legal_basis_note'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['entry_key' => [$exception->getMessage()]]);
        }

        return response()->json(['message' => 'Retention policy saved', 'data' => $policy]);
    }

    #[OA\Delete(
        path: '/api/v1/settings/privacy/retention-policies/{entryKey}',
        operationId: 'deletePrivacyRetentionPolicy',
        description: 'Removes the retention policy of a table. Data already anonymised stay anonymised.',
        summary: 'Delete a retention policy',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(name: 'entryKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 204, description: 'Policy deleted'),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function deletePolicy(Request $request, string $entryKey): JsonResponse
    {
        PrivacyRetentionPolicy::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId($request))
            ->where('entry_key', $entryKey)
            ->firstOrFail()
            ->delete();

        return response()->json(null, 204);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/retention-runs',
        operationId: 'runPrivacyRetention',
        description: 'Runs the active retention policies now (or the one of entry_key). dry_run defaults to true: the rows that would be anonymised are only counted and a sample of their ids is returned. With dry_run=false the rows are anonymised, the run is logged and written to the audit ledger.',
        summary: 'Run retention policies',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionRunRequest')),
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Runs executed', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionRunListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function runRetention(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'dry_run' => ['nullable', 'boolean'],
            'entry_key' => ['nullable', 'string', 'max:120'],
        ]);

        $runs = $this->retention->runForTenant($this->tenantId($request), (bool) ($validated['dry_run'] ?? true), $validated['entry_key'] ?? null, $this->actorId($request));

        return response()->json(['data' => $runs]);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/retention-runs',
        operationId: 'listPrivacyRetentionRuns',
        description: 'Log of the executions and dry runs of the retention policies, newest first (latest 200).',
        summary: 'List retention runs',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Runs', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionRunListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function retentionRuns(Request $request): JsonResponse
    {
        $runs = PrivacyRetentionRun::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $this->tenantId($request))
            ->orderByDesc('started_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $runs]);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/retention-suggestions',
        operationId: 'listPrivacyRetentionSuggestions',
        description: 'Suggested retention periods for the tables that can have a retention policy, with the rationale and the policy the company has now. They are operational suggestions, not legal requirements and not verified against any statute: they are never applied automatically and the company must confirm them (save a policy, or adopt a suggestion, which creates it inactive).',
        summary: 'List suggested retention periods',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        responses: [
            new OA\Response(response: 200, description: 'Suggestions', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionSuggestionListResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function retentionSuggestions(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->suggestions->list($this->tenantId($request))]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/retention-suggestions/{entryKey}/adopt',
        operationId: 'adoptPrivacyRetentionSuggestion',
        description: 'Creates the retention policy of the table from its suggestion, only with confirm=true, and inactive unless activate=true. A policy already present is never overwritten. Nothing is anonymised until the policy is active and run.',
        summary: 'Adopt a suggested retention period',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionAdoptRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(name: 'entryKey', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [
            new OA\Response(response: 201, description: 'Policy created', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRetentionPolicyResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function adoptSuggestion(Request $request, string $entryKey): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => ['required', 'accepted'],
            'retention_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'activate' => ['nullable', 'boolean'],
        ]);

        try {
            $policy = $this->suggestions->adopt($this->tenantId($request), $entryKey, $this->retention, isset($validated['retention_days']) ? (int) $validated['retention_days'] : null, (bool) ($validated['activate'] ?? false));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['entry_key' => [$exception->getMessage()]]);
        }

        return response()->json(['message' => 'Retention policy created from the suggestion', 'data' => $policy], 201);
    }

    #[OA\Get(
        path: '/api/v1/settings/privacy/breaches/{id}',
        operationId: 'showPrivacyBreach',
        description: 'A personal data breach with the time left to the 72 hour notification deadline.',
        summary: 'Show a personal data breach',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyBreachId')],
        responses: [
            new OA\Response(response: 200, description: 'Breach', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function showBreach(Request $request, string $id): JsonResponse
    {
        return response()->json(['data' => $this->breaches->find($this->tenantId($request), $id)]);
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/breaches/{id}/contain',
        operationId: 'containPrivacyBreach',
        description: 'Marks the breach as contained (investigating to contained), optionally recording the measures taken.',
        summary: 'Contain a breach',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyBreachContainRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyBreachId')],
        responses: [
            new OA\Response(response: 200, description: 'Breach contained', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function containBreach(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['mitigation_measures' => ['nullable', 'string', 'max:2000']]);
        $breach = $this->breaches->find($this->tenantId($request), $id);

        return $this->doneBreach('Breach contained', fn (): PrivacyDataBreach => $this->breaches->contain($breach, $validated['mitigation_measures'] ?? null, $this->actorId($request)));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/breaches/{id}/notify-authority',
        operationId: 'notifyPrivacyBreachAuthority',
        description: 'Records the notification to the supervisory authority (regulation nLPD: Swiss FDPIC; GDPR: the competent authority). A notification after the 72 hour deadline requires delay_reason (art. 33(1) GDPR). The system records the notification: it does not send it.',
        summary: 'Record the notification to the authority',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyBreachNotifyAuthorityRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyBreachId')],
        responses: [
            new OA\Response(response: 200, description: 'Notification recorded', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function notifyAuthority(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'regulation' => ['required', Rule::in(['nLPD', 'GDPR'])],
            'notified_at' => ['nullable', 'date'],
            'delay_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $breach = $this->breaches->find($this->tenantId($request), $id);
        $notifiedAt = isset($validated['notified_at']) ? CarbonImmutable::parse($validated['notified_at']) : null;

        return $this->doneBreach('Authority notification recorded', fn (): PrivacyDataBreach => $this->breaches->notifyAuthority($breach, $validated['regulation'], $notifiedAt, $validated['delay_reason'] ?? null, $this->actorId($request)));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/breaches/{id}/notification-not-required',
        operationId: 'markPrivacyBreachNotificationNotRequired',
        description: 'Declares that the breach is unlikely to result in a risk to the data subjects, so the authority notification is not required, with the reason that documents the decision (art. 33(1) GDPR accountability).',
        summary: 'Declare the authority notification not required',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyRejectRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyBreachId')],
        responses: [
            new OA\Response(response: 200, description: 'Decision recorded', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function notificationNotRequired(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $breach = $this->breaches->find($this->tenantId($request), $id);

        return $this->doneBreach('Decision recorded', fn (): PrivacyDataBreach => $this->breaches->markAuthorityNotificationNotRequired($breach, $validated['reason'], $this->actorId($request)));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/breaches/{id}/notify-subjects',
        operationId: 'notifyPrivacyBreachSubjects',
        description: 'Records that the data subjects were notified (art. 34 GDPR, nLPD art. 24(4)). The system records the notification: it does not send it.',
        summary: 'Record the notification to the data subjects',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(ref: '#/components/schemas/PrivacyBreachNotifySubjectsRequest')),
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyBreachId')],
        responses: [
            new OA\Response(response: 200, description: 'Notification recorded', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function notifySubjects(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['notified_at' => ['nullable', 'date']]);
        $breach = $this->breaches->find($this->tenantId($request), $id);
        $notifiedAt = isset($validated['notified_at']) ? CarbonImmutable::parse($validated['notified_at']) : null;

        return $this->doneBreach('Data subject notification recorded', fn (): PrivacyDataBreach => $this->breaches->notifySubjects($breach, $notifiedAt, $this->actorId($request)));
    }

    #[OA\Post(
        path: '/api/v1/settings/privacy/breaches/{id}/close',
        operationId: 'closePrivacyBreach',
        description: 'Closes the breach. The authority must have been notified (or the notification declared not required with a reason) and, when required, the data subjects notified.',
        summary: 'Close a breach',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Settings', 'Privacy'],
        parameters: [new OA\Parameter(ref: '#/components/parameters/PrivacyBreachId')],
        responses: [
            new OA\Response(response: 200, description: 'Breach closed', content: new OA\JsonContent(ref: '#/components/schemas/PrivacyDataBreachResponse')),
            new OA\Response(ref: '#/components/responses/PrivacyNotFound', response: 404),
            new OA\Response(ref: '#/components/responses/PrivacyUnprocessable', response: 422),
            new OA\Response(ref: '#/components/responses/PrivacyUnauthenticated', response: 401),
            new OA\Response(ref: '#/components/responses/PrivacyForbidden', response: 403),
        ]
    )]
    public function closeBreach(Request $request, string $id): JsonResponse
    {
        $breach = $this->breaches->find($this->tenantId($request), $id);

        return $this->doneBreach('Breach closed', fn (): PrivacyDataBreach => $this->breaches->close($breach, $this->actorId($request)));
    }

    /**
     * @param  Closure(): PrivacyDataSubjectRequest  $action
     */
    private function done(string $message, Closure $action): JsonResponse
    {
        try {
            $dsr = $action();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        return response()->json(['message' => $message, 'data' => $dsr->load('events')]);
    }

    /**
     * @param  Closure(): PrivacyDataBreach  $action
     */
    private function doneBreach(string $message, Closure $action): JsonResponse
    {
        try {
            $breach = $action();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['status' => [$exception->getMessage()]]);
        }

        return response()->json(['message' => $message, 'data' => $breach]);
    }

    private function actorId(Request $request): ?string
    {
        return $request->user() ? (string) $request->user()->id : null;
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
