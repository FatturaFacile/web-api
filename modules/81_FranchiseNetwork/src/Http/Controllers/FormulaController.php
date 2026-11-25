<?php

declare(strict_types=1);

namespace Modules\FranchiseNetwork\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\FranchiseNetwork\Exceptions\FranchiseNetworkException;
use Modules\FranchiseNetwork\Http\Controllers\Concerns\InteractsWithFranchiseNetwork;
use Modules\FranchiseNetwork\Http\Requests\CloneFormulaRequest;
use Modules\FranchiseNetwork\Http\Requests\SaveFormulaRequest;
use Modules\FranchiseNetwork\Http\Requests\SimulateFormulaRequest;
use Modules\FranchiseNetwork\Http\Resources\FormulaResource;
use Modules\FranchiseNetwork\Models\FranchiseAgreement;
use Modules\FranchiseNetwork\Models\FranchiseFormula;
use Modules\FranchiseNetwork\Services\FormulaService;
use OpenApi\Attributes as OA;

/**
 * Royalty and marketing fund formulas: progressive brackets, minimum guarantee and start-up exemption.
 */
final class FormulaController extends Controller
{
    use ApiResponse;
    use InteractsWithFranchiseNetwork;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    public function __construct(private readonly FormulaService $formulas) {}

    #[OA\Get(path: '/api/v1/franchise-network/formulas', operationId: 'franchiseNetworkListFormulas', summary: 'List the formulas (presets included)', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 200, description: 'Formulas')])]
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', FranchiseFormula::class);

        return $this->successResponse(FormulaResource::collection(FranchiseFormula::query()->orderByDesc('is_preset')->orderBy('code')->get()), $this->message('listed'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/formulas', operationId: 'franchiseNetworkCreateFormula', summary: 'Create a formula', security: self::SECURITY, tags: ['Franchise Network'], responses: [new OA\Response(response: 201, description: 'Formula created')])]
    public function store(SaveFormulaRequest $request): JsonResponse
    {
        Gate::authorize('create', FranchiseFormula::class);

        return $this->successResponse(new FormulaResource($this->formulas->save($request->validated(), null, $this->userId())), $this->message('saved'), 201);
    }

    #[OA\Put(path: '/api/v1/franchise-network/formulas/{id}', operationId: 'franchiseNetworkUpdateFormula', summary: 'Update a formula', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Formula updated')])]
    public function update(SaveFormulaRequest $request, string $id): JsonResponse
    {
        $formula = FranchiseFormula::query()->findOrFail($id);
        Gate::authorize('manage', FranchiseFormula::class);

        return $this->successResponse(new FormulaResource($this->formulas->save($request->validated(), $formula, $this->userId())), $this->message('saved'));
    }

    #[OA\Delete(path: '/api/v1/franchise-network/formulas/{id}', operationId: 'franchiseNetworkDeleteFormula', summary: 'Delete a formula that no agreement uses', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Formula deleted')])]
    public function destroy(string $id): JsonResponse
    {
        $formula = FranchiseFormula::query()->findOrFail($id);
        Gate::authorize('delete', $formula);

        if (FranchiseAgreement::query()->where('formula_id', $formula->id)->exists()) {
            throw new FranchiseNetworkException('formula_in_use', 409);
        }

        $formula->delete();

        return $this->successResponse(null, $this->message('deleted'));
    }

    #[OA\Post(path: '/api/v1/franchise-network/formulas/{id}/clone', operationId: 'franchiseNetworkCloneFormula', summary: 'Copy a formula (a preset for instance) to tune it', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 201, description: 'Formula copied')])]
    public function duplicate(CloneFormulaRequest $request, string $id): JsonResponse
    {
        $source = FranchiseFormula::query()->findOrFail($id);
        Gate::authorize('manage', FranchiseFormula::class);

        return $this->successResponse(new FormulaResource($this->formulas->cloneFrom($source, (string) $request->validated('code'), $this->userId())), $this->message('saved'), 201);
    }

    #[OA\Post(path: '/api/v1/franchise-network/formulas/{id}/simulate', operationId: 'franchiseNetworkSimulateFormula', summary: 'Royalty and marketing fund of a hypothetical month', security: self::SECURITY, tags: ['Franchise Network'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [new OA\Response(response: 200, description: 'Simulation')])]
    public function simulate(SimulateFormulaRequest $request, string $id): JsonResponse
    {
        $formula = FranchiseFormula::query()->findOrFail($id);
        Gate::authorize('viewAny', FranchiseFormula::class);
        $data = $request->validated();

        return $this->successResponse($this->formulas->simulate($formula, (string) $data['net_sales'], $data['opened_on'] ?? null, $data['period']), $this->message('simulated'));
    }
}
