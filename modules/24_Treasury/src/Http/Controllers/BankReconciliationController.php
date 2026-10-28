<?php

declare(strict_types=1);

namespace Modules\Treasury\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Treasury\Actions\ImportBankStatementAction;
use Modules\Treasury\Actions\ReconcileBankStatementAction;
use Modules\Treasury\Contracts\BankReconciliationServiceInterface;
use Modules\Treasury\Http\Requests\ImportStatementRequest;
use Modules\Treasury\Http\Requests\RunReconciliationRequest;
use Modules\Treasury\Http\Resources\BankReconciliationResource;
use Modules\Treasury\Http\Resources\BankStatementResource;
use Modules\Treasury\Models\BankReconciliation;

final class BankReconciliationController extends Controller
{
    public function __construct(
        private readonly BankReconciliationServiceInterface $reconciliationService,
        private readonly ImportBankStatementAction $importAction,
        private readonly ReconcileBankStatementAction $reconcileAction
    ) {}

    public function index(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        $reconciliations = BankReconciliation::where('tenant_id', $tenantId)
            ->latest('period_end_date')
            ->paginate((int) $request->query('per_page', 25));

        return response()->json(BankReconciliationResource::collection($reconciliations)->response()->getData(true));
    }

    public function importStatement(ImportStatementRequest $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validated();
        $data['tenant_id'] = $tenantContext->getTenantId();
        $lines = $data['lines'];
        unset($data['lines']);

        $statement = $this->importAction->execute($data, $lines);

        return response()->json(new BankStatementResource($statement), 201);
    }

    public function runAutoReconciliation(RunReconciliationRequest $request): JsonResponse
    {
        $statementId = (string) $request->input('bank_statement_id');
        $recon = $this->reconcileAction->execute($statementId, (string) $request->user()?->id);

        return response()->json(new BankReconciliationResource($recon));
    }

    public function matchManually(Request $request, string $lineId): JsonResponse
    {
        $request->validate([
            'bank_reconciliation_id' => ['required', 'uuid'],
            'matched_entity_type' => ['required', 'string'],
            'matched_entity_id' => ['required', 'uuid'],
            'allocated_amount' => ['nullable', 'numeric'],
        ]);

        $this->reconciliationService->matchManually(
            $lineId,
            $request->all(),
            (string) ($request->user()?->id ?? '')
        );

        return response()->json(['message' => 'Statement line matched successfully.']);
    }

    public function approve(Request $request, string $id): JsonResponse
    {
        $recon = $this->reconciliationService->approveReconciliation(
            $id,
            (string) ($request->user()?->id ?? '')
        );

        return response()->json(new BankReconciliationResource($recon));
    }
}
