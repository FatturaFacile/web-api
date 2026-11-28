<?php

declare(strict_types=1);

namespace Modules\BondedWarehouses\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\BondedWarehouses\Application\Queries\RegistryQueries;
use Modules\BondedWarehouses\Domain\Exceptions\BondedWarehouseException;
use Modules\BondedWarehouses\Http\Controllers\Concerns\InteractsWithBondedWarehouses;
use Modules\BondedWarehouses\Http\Requests\SaveAuthorizationRequest;
use Modules\BondedWarehouses\Http\Resources\AuthorizationResource;
use Modules\BondedWarehouses\Models\CustomsWarehouseAuthorization;
use OpenApi\Attributes as OA;

/**
 * ADM / UDSC / BAZG warehouse authorizations with their CGU ceiling and AEO status.
 */
final class AuthorizationController extends Controller
{
    use ApiResponse;
    use InteractsWithBondedWarehouses;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/bonded-warehouses/authorizations', operationId: 'bondedWarehousesAuthorizationsIndex', summary: 'Customs and IVA warehouse authorizations', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Authorizations')])]
    public function index(RegistryQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', CustomsWarehouseAuthorization::class);

        return $this->paged($queries->authorizations($this->filters(), $this->perPage()), AuthorizationResource::class);
    }

    #[OA\Post(path: '/api/v1/bonded-warehouses/authorizations', operationId: 'bondedWarehousesAuthorizationsStore', summary: 'Register a warehouse authorization', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 201, description: 'Authorization')])]
    public function store(SaveAuthorizationRequest $request): JsonResponse
    {
        Gate::authorize('create', CustomsWarehouseAuthorization::class);

        $authorization = CustomsWarehouseAuthorization::query()->create($request->validated() + ['cgu_utilized_amount' => 0]);

        return $this->createdResponse(new AuthorizationResource($authorization->refresh()), $this->message('authorization_saved'));
    }

    #[OA\Get(path: '/api/v1/bonded-warehouses/authorizations/{id}', operationId: 'bondedWarehousesAuthorizationsShow', summary: 'One authorization', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Authorization')])]
    public function show(string $id): JsonResponse
    {
        $authorization = CustomsWarehouseAuthorization::query()->findOrFail($id);
        Gate::authorize('view', $authorization);

        return $this->successResponse(new AuthorizationResource($authorization), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/bonded-warehouses/authorizations/{id}', operationId: 'bondedWarehousesAuthorizationsUpdate', summary: 'Change an authorization or its CGU ceiling', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Authorization')])]
    public function update(SaveAuthorizationRequest $request, string $id): JsonResponse
    {
        $authorization = CustomsWarehouseAuthorization::query()->findOrFail($id);
        Gate::authorize('update', $authorization);

        if ((float) $request->validated('cgu_max_ceiling_amount') + 0.004 < (float) $authorization->cgu_utilized_amount) {
            throw BondedWarehouseException::because('ceiling_below_utilized', ['utilized' => $authorization->cgu_utilized_amount]);
        }

        $authorization->update($request->validated());

        return $this->successResponse(new AuthorizationResource($authorization->refresh()), $this->message('authorization_saved'));
    }

    #[OA\Delete(path: '/api/v1/bonded-warehouses/authorizations/{id}', operationId: 'bondedWarehousesAuthorizationsDestroy', summary: 'Delete an authorization without stock or register rows', security: self::SECURITY, tags: ['Bonded warehouses'], responses: [new OA\Response(response: 200, description: 'Deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $authorization = CustomsWarehouseAuthorization::query()->findOrFail($id);
        Gate::authorize('delete', $authorization);

        if ($authorization->lots()->exists() || (float) $authorization->cgu_utilized_amount > 0.0) {
            throw BondedWarehouseException::because('authorization_in_use', [], 409);
        }

        $authorization->delete();

        return $this->successResponse(null, $this->message('authorization_deleted'));
    }
}
