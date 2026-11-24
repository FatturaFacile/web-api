<?php

declare(strict_types=1);

namespace Modules\AnalyticalAccounting\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Modules\AnalyticalAccounting\Http\Controllers\Concerns\InteractsWithAnalytical;
use Modules\AnalyticalAccounting\Http\Requests\DerivationRuleRequest;
use Modules\AnalyticalAccounting\Http\Requests\DerivationTestRequest;
use Modules\AnalyticalAccounting\Http\Resources\DerivationRuleResource;
use Modules\AnalyticalAccounting\Models\DerivationRule;
use Modules\AnalyticalAccounting\Services\AnalyticalPostingService;

/**
 * Prioritized derivation rules and the test endpoint that shows what they would derive for a posting.
 */
final class DerivationRuleController extends Controller
{
    use InteractsWithAnalytical;

    public function __construct(private readonly AnalyticalPostingService $posting) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', DerivationRule::class);

        return DerivationRuleResource::collection(DerivationRule::query()->where('tenant_id', $this->tenantId())->orderBy('priority')->orderBy('name')->paginate($this->perPage()));
    }

    public function store(DerivationRuleRequest $request): JsonResponse
    {
        $this->authorize('create', DerivationRule::class);
        $rule = DerivationRule::create(['tenant_id' => $this->tenantId(), 'is_active' => true, 'created_by' => $this->userId(), ...$request->validated()]);

        return (new DerivationRuleResource($rule))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(string $id): DerivationRuleResource
    {
        $rule = $this->find($id);
        $this->authorize('view', $rule);

        return new DerivationRuleResource($rule);
    }

    public function update(DerivationRuleRequest $request, string $id): DerivationRuleResource
    {
        $rule = $this->find($id);
        $this->authorize('update', $rule);
        $rule->fill([...$request->validated(), 'updated_by' => $this->userId()])->save();

        return new DerivationRuleResource($rule);
    }

    public function destroy(string $id): Response
    {
        $rule = $this->find($id);
        $this->authorize('delete', $rule);
        $rule->delete();

        return response()->noContent();
    }

    public function test(DerivationTestRequest $request): JsonResponse
    {
        $this->authorize('viewAny', DerivationRule::class);
        $data = $request->validated();
        $explicit = $data['explicit'] ?? [];
        unset($data['explicit']);

        return response()->json(['success' => true, 'data' => $this->posting->testDerivation($this->tenantId(), $data, $explicit)]);
    }

    private function find(string $id): DerivationRule
    {
        return DerivationRule::query()->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}
