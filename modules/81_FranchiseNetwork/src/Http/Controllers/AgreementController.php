<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Domain\Enums\AgreementStatus;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\ListFiltersRequest;
use Modules\FranchiseNetwork\Http\Requests\RenewAgreementRequest;
use Modules\FranchiseNetwork\Http\Requests\SaveAgreementRequest;
use Modules\FranchiseNetwork\Http\Resources\AgreementResource;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;
use Modules\FranchiseNetwork\Services\AgreementService;
use OpenApi\Attributes as OA;

/**
 * Affiliation agreements: drafting with the L. 129/2004 findings, activation, renewal, termination and the alerts of the franchisor.
 */
final class AgreementController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly AgreementService $agreements) {}

    #[OA\Get(path: '/api/v1/franchise-network/agreements', operationId: 'franchiseNetworkListAgreements', summary: 'List the affiliation agreements', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Agreements, paginated')])]
    public function index(ListFiltersRequest $request): JsonResponse
    {
        Gate::authorize('manage', FranchiseAgreement::class);

        $page = FranchiseAgreement::query()->with('store')
            ->when($request->input('status'), fn ($rows, $status) => $rows->where('status', $status))
            ->when($request->input('store_id'), fn ($rows, $store) => $rows->where('store_id', $store))
            ->orderByDesc('end_date')
            ->paginate($this->perPage(50));

        return $this->successResponse(
            $page->getCollection()->map(fn (FranchiseAgreement $agreement) => new AgreementResource($agreement, $this->agreements->findings($agreement), $this->agreements->alerts($agreement)))->all(),
            $this->message('listed'),
            200,
            ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        );
    }

    #[OA\Get(path: '/api/v1/franchise-network/agreements/alerts', operationId: 'franchiseNetworkAgreementAlerts', summary: 'Agreements about to renew, expired or with a guarantee about to lapse', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Agreements with alerts')])]
    public function alerts(): JsonResponse
    {
        Gate::authorize('manage', FranchiseAgreement::class);

        return $this->successResponse(array_map(
            fn (array $row) => new AgreementResource($row['agreement'], $this->agreements->findings($row['agreement']), $row['alerts']),
            $this->agreements->attentionList(),
        ), $this->message('listed'));
    }

    #[OA\Get(path: '/api/v1/franchise-network/agreements/{id}', operationId: 'franchiseNetworkShowAgreement', summary: 'Show an agreement with its findings and the entry fee deferral schedule', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agreement')])]
    public function show(string $id): JsonResponse
    {
        $agreement = FranchiseAgreement::query()->with(['store', 'deferrals'])->findOrFail($id);
        Gate::authorize('manage', FranchiseAgreement::class);

        return $this->successResponse($this->resource($agreement), $this->message('loaded'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/agreements', operationId: 'franchiseNetworkCreateAgreement', summary: 'Draft an affiliation agreement', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 201, description: 'Agreement created')])]
    public function store(SaveAgreementRequest $request): JsonResponse
    {
        Gate::authorize('create', FranchiseAgreement::class);
        $agreement = $this->agreements->save($request->validated(), null, $this->userId());

        return $this->successResponse($this->resource($agreement->load(['store', 'deferrals'])), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/franchise-network/agreements/{id}', operationId: 'franchiseNetworkUpdateAgreement', summary: 'Update an agreement that is not closed', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agreement updated')])]
    public function update(SaveAgreementRequest $request, string $id): JsonResponse
    {
        $agreement = FranchiseAgreement::query()->findOrFail($id);
        Gate::authorize('update', $agreement);
        $agreement = $this->agreements->save($request->validated(), $agreement, $this->userId());

        return $this->successResponse($this->resource($agreement->load(['store', 'deferrals'])), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/franchise-network/agreements/{id}', operationId: 'franchiseNetworkDeleteAgreement', summary: 'Delete a draft agreement', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agreement deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $agreement = FranchiseAgreement::query()->findOrFail($id);
        Gate::authorize('delete', $agreement);

        if ($agreement->status !== AgreementStatus::Draft) {
            throw new FranchiseNetworkException('agreement_not_draft', 409);
        }

        $agreement->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/agreements/{id}/activate', operationId: 'franchiseNetworkActivateAgreement', summary: 'Activate a draft agreement', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agreement active')])]
    public function activate(string $id): JsonResponse
    {
        $agreement = FranchiseAgreement::query()->findOrFail($id);
        Gate::authorize('update', $agreement);

        return $this->successResponse($this->resource($this->agreements->activate($agreement, $this->userId())->load(['store', 'deferrals'])), $this->message('agreement_activated'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/agreements/{id}/terminate', operationId: 'franchiseNetworkTerminateAgreement', summary: 'Terminate an active agreement', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Agreement terminated')])]
    public function terminate(string $id): JsonResponse
    {
        $agreement = FranchiseAgreement::query()->findOrFail($id);
        Gate::authorize('update', $agreement);

        return $this->successResponse($this->resource($this->agreements->terminate($agreement, $this->userId())->load('store')), $this->message('agreement_terminated'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/agreements/{id}/renew', operationId: 'franchiseNetworkRenewAgreement', summary: 'Renew an active agreement until a new end date', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Renewed agreement')])]
    public function renew(RenewAgreementRequest $request, string $id): JsonResponse
    {
        $agreement = FranchiseAgreement::query()->findOrFail($id);
        Gate::authorize('update', $agreement);

        return $this->successResponse($this->resource($this->agreements->renew($agreement, (string) $request->validated('end_date'), $this->userId())->load('store')), $this->message('agreement_renewed'), 201);
    }

    private function resource(FranchiseAgreement $agreement): AgreementResource
    {
        return new AgreementResource($agreement, $this->agreements->findings($agreement), $this->agreements->alerts($agreement));
    }
}
