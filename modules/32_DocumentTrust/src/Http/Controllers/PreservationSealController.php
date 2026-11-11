<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Documents\Models\DmsDocument;
use Modules\Documents\Models\DmsDocumentVersion;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\PreservationItem;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Services\PreservationAccessLogger;
use Modules\DocumentTrust\Services\PreservationPackageService;
use Modules\DocumentTrust\Services\PreservationSealService;
use Modules\GeneralLedger\Models\FiscalYear;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Files the ledger book and the payslips in the document library, ready to be added to a preservation package (or added
 * straight to an open one). Nothing here is legal preservation by itself.
 */
final class PreservationSealController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(
        private readonly PreservationSealService $seal,
        private readonly PreservationPackageService $packages,
        private readonly PreservationAccessLogger $logger,
    ) {}

    #[OA\Post(path: '/api/v1/document-trust/preservation-seal/journal-book', operationId: 'sealJournalBook', description: 'Generates the libro giornale CSV of a fiscal year (the same export as the General Ledger download) and files it in the document library as a `ledger_book` document, with its metadata. With `package_id` the document is also added to that open `ledger_book` package. Repeating it with unchanged data returns the same document (`reused`). Needs ledger write access.', summary: 'File the ledger book for preservation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['fiscal_year_id'], properties: [
        new OA\Property(property: 'fiscal_year_id', type: 'string', format: 'uuid'),
        new OA\Property(property: 'from', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'to', type: 'string', format: 'date', nullable: true),
        new OA\Property(property: 'folder_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'package_id', type: 'string', format: 'uuid', nullable: true),
    ])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 201, description: 'The library document (and the package item when requested)', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Fiscal year or package not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'The package is closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * File the ledger book of a fiscal year.
     */
    public function journalBook(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'fiscal_year_id' => ['required', 'uuid'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'folder_id' => ['nullable', 'uuid', Rule::exists('dms_folders', 'id')->where('tenant_id', $tenantContext->getTenantId())->whereNull('deleted_at')],
            'package_id' => ['nullable', 'uuid'],
        ]);

        $fiscalYear = FiscalYear::withoutGlobalScopes()->where('tenant_id', $tenantContext->getTenantId())->findOrFail($data['fiscal_year_id']);
        $package = $this->openPackage($data['package_id'] ?? null, 'ledger_book');

        return $this->guarded(function () use ($request, $data, $fiscalYear, $package): JsonResponse {
            $filed = $this->seal->journalBook($fiscalYear, $data['from'] ?? null, $data['to'] ?? null, $data['folder_id'] ?? null, $request->user());

            return $this->respond([$filed], $package, 'journal_book', ['fiscal_year_id' => (string) $fiscalYear->id]);
        });
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-seal/payslips', operationId: 'sealPayslips', description: 'Renders the payslips of the closed payroll runs of a month with the Payroll PDF generator and files each in the document library as a `payslip` document. With `package_id` they are also added to that open `payslip` package. A payslip already filed is reused, not rendered again. Needs payroll write access.', summary: 'File the payslips of a month for preservation', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['year', 'month'], properties: [
        new OA\Property(property: 'year', type: 'integer', example: 2027),
        new OA\Property(property: 'month', type: 'integer', example: 3),
        new OA\Property(property: 'folder_id', type: 'string', format: 'uuid', nullable: true),
        new OA\Property(property: 'package_id', type: 'string', format: 'uuid', nullable: true),
    ])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 201, description: 'The library documents (and the package items when requested)', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Package not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 409, description: 'The package is closed', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'No payslip of a closed run, or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * File the payslips of a month.
     */
    public function payslips(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'folder_id' => ['nullable', 'uuid', Rule::exists('dms_folders', 'id')->where('tenant_id', $tenantContext->getTenantId())->whereNull('deleted_at')],
            'package_id' => ['nullable', 'uuid'],
        ]);

        $package = $this->openPackage($data['package_id'] ?? null, 'payslip');

        return $this->guarded(fn (): JsonResponse => $this->respond(
            $this->seal->payslips((int) $data['year'], (int) $data['month'], $data['folder_id'] ?? null, $request->user()),
            $package,
            'payslips',
            ['year' => (int) $data['year'], 'month' => (int) $data['month']],
        ));
    }

    /**
     * @param  list<array{document: DmsDocument, reused: bool}>  $filed
     * @param  array<string, mixed>  $details
     */
    private function respond(array $filed, ?PreservationPackage $package, string $kind, array $details): JsonResponse
    {
        $rows = [];

        foreach ($filed as $row) {
            $document = $row['document'];
            $item = $package === null ? null : (PreservationItem::where('package_id', $package->id)->where('source_type', 'dms_document')->where('source_id', $document->id)->first() ?? $this->packages->addItem($package, (string) $document->id));
            $rows[] = [
                'document_id' => (string) $document->id,
                'title' => $document->title,
                'reused' => $row['reused'],
                'sha256' => DmsDocumentVersion::where('document_id', $document->id)->orderByDesc('version_number')->value('sha256'),
                'package_item_id' => $item?->id,
            ];
        }

        $this->logger->record('seal', $package, ['kind' => $kind, 'documents' => count($rows), 'package_id' => $package?->id, ...$details], subject: $filed[0]['document']);

        return $this->successResponse($rows, 'Documents filed for preservation', Response::HTTP_CREATED);
    }

    private function openPackage(?string $id, string $class): ?PreservationPackage
    {
        if ($id === null) {
            return null;
        }

        $package = PreservationPackage::findOrFail($id);
        Gate::authorize('update', $package);

        if ($package->document_class !== $class) {
            throw ValidationException::withMessages(['package_id' => "The package is not a {$class} package."]);
        }

        return $package;
    }

    /**
     * @param  callable(): JsonResponse  $operation
     */
    private function guarded(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (DocumentTrustException $exception) {
            throw ValidationException::withMessages(['seal' => $exception->getMessage()]);
        }
    }
}
