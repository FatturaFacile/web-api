<?php

declare(strict_types=1);

namespace Modules\CorporateConventions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Modules\CorporateConventions\Application\Commands\CreateCorporateConventionCommand;
use Modules\CorporateConventions\Application\Commands\CreateCorporateConventionHandler;
use Modules\CorporateConventions\Application\Commands\ImportConventionHashedIdentifiersCommand;
use Modules\CorporateConventions\Application\Commands\ImportConventionHashedIdentifiersHandler;
use Modules\CorporateConventions\Application\Commands\UpdateCorporateConventionCommand;
use Modules\CorporateConventions\Application\Commands\UpdateCorporateConventionHandler;
use Modules\CorporateConventions\Domain\Enums\ConventionStatus;
use Modules\CorporateConventions\Exceptions\ConventionException;
use Modules\CorporateConventions\Http\Controllers\Concerns\InteractsWithConventions;
use Modules\CorporateConventions\Http\Requests\ContractFileRequest;
use Modules\CorporateConventions\Http\Requests\ImportIdentifiersRequest;
use Modules\CorporateConventions\Http\Requests\ListFiltersRequest;
use Modules\CorporateConventions\Http\Requests\SaveConventionRequest;
use Modules\CorporateConventions\Http\Requests\TransitionRequest;
use Modules\CorporateConventions\Http\Resources\ConventionResource;
use Modules\CorporateConventions\Models\ConventionAllowedHash;
use Modules\CorporateConventions\Models\CorporatePartnerConvention;
use Modules\CorporateConventions\Services\ConventionService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Corporate conventions: partners, eligibility rules, discount matrix, employer subsidy, SHA-256 employee list, contract and logo.
 */
final class ConventionController extends Controller
{
    use ApiResponse;
    use InteractsWithConventions;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly ConventionService $conventions) {}

    #[OA\Get(path: '/api/v1/sales/corporate-conventions', operationId: 'corporateConventionsList', summary: 'List the corporate conventions with their enrolment counts', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 200, description: 'Conventions, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', CorporatePartnerConvention::class);

        return $this->paged($this->conventions->query($request->validated())->paginate($this->perPage()), ConventionResource::class);
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions', operationId: 'corporateConventionsCreate', summary: 'Create a draft convention with its discount matrix', security: self::SECURITY, tags: ['Corporate Conventions'], responses: [new OA\Response(response: 201, description: 'Convention created')])]
    public function store(SaveConventionRequest $request, CreateCorporateConventionHandler $handler): JsonResponse
    {
        Gate::authorize('create', CorporatePartnerConvention::class);
        $convention = $handler->handle(new CreateCorporateConventionCommand($request->validated(), $this->tenantId(), $this->userId()));

        return $this->successResponse(new ConventionResource($convention), $this->message('saved'), 201);
    }

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/{id}', operationId: 'corporateConventionsShow', summary: 'Show a convention with its discount matrix', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Convention')])]
    public function show(string $id): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->with('discountRules')->findOrFail($id);
        Gate::authorize('view', $convention);

        return $this->successResponse(new ConventionResource($convention), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/sales/corporate-conventions/{id}', operationId: 'corporateConventionsUpdate', summary: 'Update a convention and replace its discount matrix', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Convention updated')])]
    public function update(SaveConventionRequest $request, string $id, UpdateCorporateConventionHandler $handler): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('update', $convention);
        $convention = $handler->handle(new UpdateCorporateConventionCommand($id, $request->validated(), $this->userId()));

        return $this->successResponse(new ConventionResource($convention), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/sales/corporate-conventions/{id}', operationId: 'corporateConventionsDelete', summary: 'Delete a convention that has no beneficiaries or purchases', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Convention deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('delete', $convention);
        $this->conventions->delete($convention, $this->userId());

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/{id}/transition', operationId: 'corporateConventionsTransition', summary: 'Activate, suspend or expire a convention', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Status updated')])]
    public function transition(TransitionRequest $request, string $id): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('update', $convention);
        $convention = $this->conventions->transition($convention, ConventionStatus::from((string) $request->validated('status')), $this->userId());

        return $this->successResponse(new ConventionResource($convention->load('discountRules')), $this->message('transitioned'));
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/{id}/identifiers', operationId: 'corporateConventionsImportIdentifiers', summary: 'Import the employer list of matricole, badges or cards, stored as SHA-256', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Import result, or the hashing preview with dry_run')])]
    public function importIdentifiers(ImportIdentifiersRequest $request, string $id, ImportConventionHashedIdentifiersHandler $handler): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('update', $convention);
        $result = $handler->handle(new ImportConventionHashedIdentifiersCommand($id, $request->identifiers(), $request->boolean('dry_run'), $this->userId()));

        return $this->successResponse($result, $this->message($request->boolean('dry_run') ? 'import_previewed' : 'imported'));
    }

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/{id}/identifiers/stats', operationId: 'corporateConventionsIdentifierStats', summary: 'Size of the employer list and how many identifiers are already used', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Counts')])]
    public function identifierStats(string $id): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('view', $convention);
        $query = ConventionAllowedHash::query()->where('convention_id', $convention->id);

        return $this->successResponse(['total' => (clone $query)->count(), 'used' => (clone $query)->where('is_used', true)->count()], $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/{id}/contract', operationId: 'corporateConventionsUploadContract', summary: 'Upload the PDF contract of the convention', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Contract stored')])]
    public function uploadContract(ContractFileRequest $request, string $id): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('update', $convention);

        return $this->successResponse(new ConventionResource($this->conventions->storeContract($convention, $request->file('file'))), $this->message('saved'));
    }

    #[OA\Get(path: '/api/v1/sales/corporate-conventions/{id}/contract', operationId: 'corporateConventionsDownloadContract', summary: 'Download the PDF contract of the convention', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'PDF file')])]
    public function downloadContract(string $id): StreamedResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('view', $convention);
        $disk = Storage::disk((string) config('corporate_conventions.storage.disk'));

        if ($convention->contract_document_path === null || ! $disk->exists($convention->contract_document_path)) {
            throw new ConventionException('convention_contract_missing', 404);
        }

        return $disk->download($convention->contract_document_path, $convention->code.'-contract.pdf', ['Content-Type' => 'application/pdf']);
    }

    #[OA\Post(path: '/api/v1/sales/corporate-conventions/{id}/logo', operationId: 'corporateConventionsUploadLogo', summary: 'Upload the logo of the landing page', security: self::SECURITY, tags: ['Corporate Conventions'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Logo stored')])]
    public function uploadLogo(ContractFileRequest $request, string $id): JsonResponse
    {
        $convention = CorporatePartnerConvention::query()->findOrFail($id);
        Gate::authorize('update', $convention);

        return $this->successResponse(new ConventionResource($this->conventions->storeLogo($convention, $request->file('file'))), $this->message('saved'));
    }
}
