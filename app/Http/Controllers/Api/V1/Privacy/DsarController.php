<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Enums\Privacy\DsarStatus;
use App\Http\Requests\Privacy\CreateDsarRequest;
use App\Http\Requests\Privacy\ExtendDsarRequest;
use App\Http\Requests\Privacy\VerifyDsarIdentityRequest;
use App\Http\Resources\Privacy\DsarRequestResource;
use App\Models\Privacy\PrivacySubjectRequestDsar;
use App\Services\Privacy\Governance\Commands\CreateDsarRequestCommand;
use App\Services\Privacy\Governance\Commands\CreateDsarRequestHandler;
use App\Services\Privacy\Governance\Commands\ExecuteSelectiveFiscalSafeAnonymizationCommand;
use App\Services\Privacy\Governance\Commands\ExecuteSelectiveFiscalSafeAnonymizationHandler;
use App\Services\Privacy\Governance\Commands\GenerateDsarPortablePackageCommand;
use App\Services\Privacy\Governance\Commands\GenerateDsarPortablePackageHandler;
use App\Services\Privacy\Governance\DsarService;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Data subject requests (GDPR art. 12/15/17/20, nLPD art. 25/28).
 */
final class DsarController extends PrivacyGovernanceController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(DsarStatus::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $page = PrivacySubjectRequestDsar::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId($request))
            ->when(isset($filters['status']), static fn ($q) => $q->where('status', $filters['status']))
            ->orderBy('statutory_deadline_at')->paginate((int) ($filters['per_page'] ?? 50));

        return DsarRequestResource::collection($page);
    }

    public function store(CreateDsarRequest $request, CreateDsarRequestHandler $handler): JsonResponse
    {
        $dsar = $handler->handle(new CreateDsarRequestCommand($this->tenantId($request), $request->validated()));

        return (new DsarRequestResource($dsar->refresh()))->response()->setStatusCode(201);
    }

    public function show(Request $request, DsarService $dsars, string $id): DsarRequestResource
    {
        return new DsarRequestResource($dsars->find($this->tenantId($request), $id));
    }

    public function verifyIdentity(VerifyDsarIdentityRequest $request, DsarService $dsars, string $id): DsarRequestResource|JsonResponse
    {
        try {
            return new DsarRequestResource($dsars->verifyIdentity($dsars->find($this->tenantId($request), $id), (string) $request->validated('method')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function extend(ExtendDsarRequest $request, DsarService $dsars, string $id): DsarRequestResource|JsonResponse
    {
        try {
            return new DsarRequestResource($dsars->extend($dsars->find($this->tenantId($request), $id), (int) $request->validated('days'), (string) $request->validated('justification')));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function generatePackage(Request $request, GenerateDsarPortablePackageHandler $handler, string $id): DsarRequestResource|JsonResponse
    {
        try {
            return new DsarRequestResource($handler->handle(new GenerateDsarPortablePackageCommand($this->tenantId($request), $id)));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function downloadPackage(Request $request, DsarService $dsars, string $id): StreamedResponse|JsonResponse
    {
        $dsar = $dsars->find($this->tenantId($request), $id);
        if ($dsar->export_artifact_path === null || ! Storage::disk('local')->exists($dsar->export_artifact_path)) {
            return response()->json(['message' => 'The package is not ready.', 'package_status' => $dsar->package_status], 409);
        }

        return Storage::disk('local')->download($dsar->export_artifact_path, "dsar_package_{$dsar->request_number}.zip");
    }

    public function executeErasure(Request $request, ExecuteSelectiveFiscalSafeAnonymizationHandler $handler, string $id): JsonResponse
    {
        $dryRun = $request->boolean('dry_run');

        try {
            $result = $handler->handle(new ExecuteSelectiveFiscalSafeAnonymizationCommand($this->tenantId($request), $id, $dryRun));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $result]);
    }
}
