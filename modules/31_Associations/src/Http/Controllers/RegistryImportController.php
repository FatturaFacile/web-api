<?php

declare(strict_types=1);

namespace Modules\Associations\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Associations\Http\Requests\CommitImportRequest;
use Modules\Associations\Http\Requests\ImportRegistryRequest;
use Modules\Associations\Models\ImportBatch;
use Modules\Associations\OpenApi\AssociationGet;
use Modules\Associations\OpenApi\AssociationPost;
use Modules\Associations\Services\RegistryImportService;
use Modules\Associations\Support\AssociationCapability;

/**
 * CSV import in two steps: a preview with the errors and duplicates of each row, then the confirmation.
 */
final class RegistryImportController extends AssociationController
{
    public function __construct(private readonly RegistryImportService $imports) {}

    #[AssociationPost('/api/v1/associations/registry-imports', 'previewAssociationImport', 'Analyse a CSV file without changing the registry', 'AssociationImportFileRequest', multipart: true)]
    public function preview(ImportRegistryRequest $request): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $batch = $this->imports->preview($request->file('file'), $this->actorId($request));

        return response()->json(['data' => ['id' => $batch->id, 'summary' => $batch->summary, 'rows' => $batch->rows]], 201);
    }

    #[AssociationGet('/api/v1/associations/registry-imports/{id}', 'showAssociationImport', 'Show the preview of an import')]
    public function show(string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $batch = ImportBatch::query()->findOrFail($id);

        return response()->json(['data' => ['id' => $batch->id, 'status' => $batch->status, 'summary' => $batch->summary, 'rows' => $batch->rows]]);
    }

    #[AssociationPost('/api/v1/associations/registry-imports/{id}/commit', 'commitAssociationImport', 'Apply an analysed import to the registry', 'AssociationImportCommitRequest')]
    public function commit(CommitImportRequest $request, string $id): JsonResponse
    {
        $this->allow(AssociationCapability::Manage);
        $batch = ImportBatch::query()->findOrFail($id);

        return response()->json(['data' => $this->imports->commit($batch, (string) ($request->validated('on_duplicate') ?? RegistryImportService::OnDuplicateSkip))]);
    }
}
