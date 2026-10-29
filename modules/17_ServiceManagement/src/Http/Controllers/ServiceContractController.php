<?php

declare(strict_types=1);

namespace Modules\ServiceManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\ServiceManagement\Actions\SubmitCSATAction;
use Modules\ServiceManagement\DTOs\SubmitCSATDTO;
use Modules\ServiceManagement\Http\Requests\SubmitCSATRequest;
use Modules\ServiceManagement\Http\Resources\CSATSurveyResource;
use Modules\ServiceManagement\Http\Resources\ServiceContractResource;
use Modules\ServiceManagement\Models\ServiceContract;

final class ServiceContractController extends Controller
{
    public function __construct(
        private readonly SubmitCSATAction $csatAction,
        private readonly TenantContext $tenantContext
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $query = ServiceContract::where('tenant_id', $tenant->id);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        $contracts = $query->orderByDesc('created_at')->paginate(25);

        return response()->json([
            'data' => ServiceContractResource::collection($contracts),
            'meta' => [
                'current_page' => $contracts->currentPage(),
                'total' => $contracts->total(),
            ],
        ]);
    }

    public function show(ServiceContract $serviceContract): JsonResponse
    {
        return response()->json([
            'data' => new ServiceContractResource($serviceContract),
        ]);
    }

    public function submitCsat(SubmitCSATRequest $request): JsonResponse
    {
        $tenant = $this->tenantContext->getTenant();
        $validated = $request->validated();

        $dto = new SubmitCSATDTO(
            tenantId: $tenant->id,
            companyId: $tenant->id,
            score: (int) $validated['score'],
            feedback: $validated['feedback'] ?? null,
            ticketId: $validated['ticket_id'] ?? null,
            workOrderId: $validated['work_order_id'] ?? null,
            customerId: $validated['customer_id'] ?? null,
            respondentEmail: $validated['respondent_email'] ?? null
        );

        $survey = $this->csatAction->execute($dto, $request->user()?->id);

        return response()->json([
            'message' => 'Customer satisfaction feedback recorded',
            'data' => new CSATSurveyResource($survey),
        ], 201);
    }
}
