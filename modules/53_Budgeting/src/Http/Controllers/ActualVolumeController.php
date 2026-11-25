<?php

declare(strict_types=1);

namespace Modules\Budgeting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Budgeting\Contracts\LedgerLookup;
use Modules\Budgeting\Http\Controllers\Concerns\InteractsWithBudgeting;
use Modules\Budgeting\Http\Requests\ActualVolumeRequest;
use Modules\Budgeting\Http\Resources\ActualVolumeResource;
use Modules\Budgeting\Models\BudgetActualVolume;
use OpenApi\Attributes as OA;

/**
 * The actual quantities per account and month that the volume and price analysis divides the actual amounts by.
 */
final class ActualVolumeController extends Controller
{
    use InteractsWithBudgeting;

    public function __construct(private readonly LedgerLookup $ledger) {}

    #[OA\Get(path: '/api/v1/budgeting/actual-volumes', operationId: 'listBudgetActualVolumes', description: 'Actual quantities by account and month. Filters: account_id, cost_center_id, project_id, month.', summary: 'List actual volumes', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], responses: [
        new OA\Response(response: 200, description: 'Volumes', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object'))])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', BudgetActualVolume::class);
        $filters = $request->validate(['account_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'], 'project_id' => ['nullable', 'uuid'], 'month' => ['nullable', 'date_format:Y-m']]);

        return ActualVolumeResource::collection(
            BudgetActualVolume::query()
                ->where('tenant_id', $this->tenantId())
                ->when(isset($filters['account_id']), static fn ($query) => $query->where('account_id', $filters['account_id']))
                ->when(isset($filters['cost_center_id']), static fn ($query) => $query->where('cost_center_id', $filters['cost_center_id']))
                ->when(isset($filters['project_id']), static fn ($query) => $query->where('project_id', $filters['project_id']))
                ->when(isset($filters['month']), static fn ($query) => $query->where('month', $filters['month']))
                ->orderByDesc('month')
                ->orderBy('account_id')
                ->paginate($this->perPage()),
        );
    }

    #[OA\Post(path: '/api/v1/budgeting/actual-volumes', operationId: 'saveBudgetActualVolume', description: 'Save the actual quantity of an account (and cost centre and project) in a month; saving the same combination again replaces the quantity.', summary: 'Save an actual volume', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object')), responses: [
        new OA\Response(response: 201, description: 'Volume saved', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', type: 'object')])),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingUnprocessable', response: 422),
    ])]
    public function store(ActualVolumeRequest $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $this->authorize('manageControl', new BudgetActualVolume(['tenant_id' => $tenantId]));
        $data = $request->validated();

        if ($this->ledger->accounts($tenantId, [$data['account_id']]) === []) {
            throw ValidationException::withMessages(['account_id' => [__('budgeting_errors.line.account_missing')]]);
        }

        if (! empty($data['cost_center_id']) && $this->ledger->costCenters($tenantId, [$data['cost_center_id']]) === []) {
            throw ValidationException::withMessages(['cost_center_id' => [__('budgeting_errors.line.cost_center_missing')]]);
        }

        if (! empty($data['project_id']) && $this->ledger->projects($tenantId, [$data['project_id']]) === []) {
            throw ValidationException::withMessages(['project_id' => [__('budgeting_errors.line.project_missing')]]);
        }

        $existing = BudgetActualVolume::query()
            ->where('tenant_id', $tenantId)
            ->where('account_id', $data['account_id'])
            ->where('month', $data['month'])
            ->when(empty($data['cost_center_id']), static fn ($query) => $query->whereNull('cost_center_id'), static fn ($query) => $query->where('cost_center_id', $data['cost_center_id']))
            ->when(empty($data['project_id']), static fn ($query) => $query->whereNull('project_id'), static fn ($query) => $query->where('project_id', $data['project_id']))
            ->first();

        $values = ['quantity' => bcadd((string) $data['quantity'], '0', 3), 'notes' => $data['notes'] ?? null];
        $volume = $existing ?? BudgetActualVolume::create([...$values, 'tenant_id' => $tenantId, 'account_id' => $data['account_id'], 'cost_center_id' => $data['cost_center_id'] ?? null, 'project_id' => $data['project_id'] ?? null, 'month' => $data['month']]);

        if ($existing !== null) {
            $existing->update($values);
        }

        return (new ActualVolumeResource($volume->refresh()))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    #[OA\Delete(path: '/api/v1/budgeting/actual-volumes/{id}', operationId: 'deleteBudgetActualVolume', description: 'Delete an actual volume (soft delete).', summary: 'Delete an actual volume', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Budgeting'], parameters: [new OA\Parameter(ref: '#/components/parameters/BudgetingId')], responses: [
        new OA\Response(response: 204, description: 'Volume deleted'),
        new OA\Response(ref: '#/components/responses/BudgetingUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/BudgetingForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/BudgetingNotFound', response: 404),
    ])]
    public function destroy(string $id): Response
    {
        $volume = BudgetActualVolume::query()->findOrFail($id);
        $this->authorize('manageControl', $volume);
        $volume->delete();

        return response()->noContent();
    }
}
