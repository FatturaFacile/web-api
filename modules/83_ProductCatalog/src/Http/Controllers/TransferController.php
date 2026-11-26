<?php

declare(strict_types=1);

namespace Modules\ProductCatalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\ProductCatalog\Domain\Enums\ImportMode;
use Modules\ProductCatalog\Http\Controllers\Concerns\InteractsWithCatalog;
use Modules\ProductCatalog\Http\Requests\ImportRequest;
use Modules\ProductCatalog\Http\Requests\ListProductsRequest;
use Modules\ProductCatalog\Services\CatalogExportService;
use Modules\ProductCatalog\Services\CatalogImportService;
use Modules\ProductCatalog\Services\CatalogSpreadsheet;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Bulk import and export of the catalog as .csv or .xlsx.
 */
final class TransferController extends Controller
{
    use ApiResponse;
    use InteractsWithCatalog;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/catalog/export', operationId: 'productCatalogExport', summary: 'Export the catalog (variants, units, kits, CH / IT fields) as csv or xlsx', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function export(ListProductsRequest $request, CatalogExportService $exports): BinaryFileResponse
    {
        $this->authorizeCatalog();
        $format = (string) ($request->validated('format') ?? 'xlsx');

        return response()->download($exports->export($format), 'catalog.'.$format)->deleteFileAfterSend(true);
    }

    #[OA\Get(path: '/api/v1/catalog/import/template', operationId: 'productCatalogImportTemplate', summary: 'The empty exchange file with the header row of the schema', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'The file')])]
    public function template(ListProductsRequest $request, CatalogExportService $exports): BinaryFileResponse
    {
        $this->authorizeCatalog();
        $format = (string) ($request->validated('format') ?? 'xlsx');

        return response()->download($exports->template($format), 'catalog-template.'.$format)->deleteFileAfterSend(true);
    }

    #[OA\Get(path: '/api/v1/catalog/import/schema', operationId: 'productCatalogImportSchema', summary: 'The columns of the exchange file', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'Columns and modes')])]
    public function schema(): JsonResponse
    {
        $this->authorizeCatalog();

        return $this->successResponse([
            'columns' => CatalogSpreadsheet::COLUMNS,
            'required' => CatalogSpreadsheet::REQUIRED,
            'modes' => ImportMode::values(),
            'max_rows' => CatalogSpreadsheet::MAX_ROWS,
        ], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/catalog/import', operationId: 'productCatalogImport', summary: 'Import the catalog from a csv or xlsx file (dry run, atomic or partial)', security: self::SECURITY, tags: ['Product Catalog'], responses: [new OA\Response(response: 200, description: 'The import report with the errors of every row')])]
    public function import(ImportRequest $request, CatalogImportService $imports): JsonResponse
    {
        $this->authorizeCatalog();
        $file = $request->file('file');
        $mode = ImportMode::tryFrom((string) $request->validated('mode')) ?? ImportMode::Atomic;
        $report = $imports->import((string) $file?->getRealPath(), strtolower((string) $file?->getClientOriginalExtension()), $mode);

        return $this->successResponse($report, $this->message($report['committed'] ? 'imported' : 'validated'));
    }
}
