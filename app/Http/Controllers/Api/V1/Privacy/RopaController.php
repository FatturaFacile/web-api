<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Privacy;

use App\Enums\Privacy\RopaLegalBasis;
use App\Http\Requests\Privacy\RopaRecordRequest;
use App\Http\Resources\Privacy\RopaRecordResource;
use App\Models\Privacy\ProcessingActivityRecordRopa;
use App\Services\Privacy\Governance\Commands\SeedDefaultRopaTemplatesCommand;
use App\Services\Privacy\Governance\Commands\SeedDefaultRopaTemplatesHandler;
use App\Services\Privacy\Governance\Queries\GetRopaRegistryExportHandler;
use App\Services\Privacy\Governance\Queries\GetRopaRegistryExportQuery;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Record of processing activities (GDPR art. 30, nLPD art. 12).
 */
final class RopaController extends PrivacyGovernanceController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'legal_basis' => ['nullable', Rule::enum(RopaLegalBasis::class)],
            'cross_border' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $this->scoped($request)->orderBy('activity_code')
            ->when(isset($filters['legal_basis']), static fn ($q) => $q->where('legal_basis', $filters['legal_basis']))
            ->when(isset($filters['is_active']), static fn ($q) => $q->where('is_active', (bool) $filters['is_active']));

        $page = $query->paginate((int) ($filters['per_page'] ?? 50));
        if (isset($filters['cross_border'])) {
            $wanted = (bool) $filters['cross_border'];
            $page->setCollection($page->getCollection()->filter(static fn (ProcessingActivityRecordRopa $r): bool => (count($r->cross_border_transfers ?? []) > 0) === $wanted)->values());
        }

        return RopaRecordResource::collection($page);
    }

    public function store(RopaRecordRequest $request): JsonResponse
    {
        $record = ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->create($request->validated() + ['tenant_id' => $this->tenantId($request)]);

        return (new RopaRecordResource($record->refresh()))->response()->setStatusCode(201);
    }

    public function update(RopaRecordRequest $request, string $id): RopaRecordResource
    {
        $record = $this->scoped($request)->whereKey($id)->firstOrFail();
        $record->update($request->validated());

        return new RopaRecordResource($record->refresh());
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->scoped($request)->whereKey($id)->firstOrFail()->delete();

        return response()->noContent();
    }

    public function seedDefaults(Request $request, SeedDefaultRopaTemplatesHandler $handler): JsonResponse
    {
        return response()->json(['data' => $handler->handle(new SeedDefaultRopaTemplatesCommand($this->tenantId($request)))]);
    }

    public function export(Request $request, GetRopaRegistryExportHandler $handler): Response
    {
        $format = (string) ($request->validate(['format' => ['nullable', Rule::in(['pdf', 'xlsx'])]])['format'] ?? 'pdf');
        $file = $handler->handle(new GetRopaRegistryExportQuery($this->tenantId($request), $format));

        return response($file['content'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['filename'].'"',
        ]);
    }

    /**
     * @return Builder<ProcessingActivityRecordRopa>
     */
    private function scoped(Request $request): Builder
    {
        return ProcessingActivityRecordRopa::withoutGlobalScope(TenantScope::class)->where('tenant_id', $this->tenantId($request));
    }
}
