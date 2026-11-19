<?php

declare(strict_types=1);

namespace Modules\Pricing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\Invoicing\Models\SalesOrder;
use Modules\Invoicing\Models\SalesQuote;
use Modules\Pricing\Http\Requests\MarginApprovalRequest;
use Modules\Pricing\Http\Resources\PricingResource;
use Modules\Pricing\Models\MarginOverrideApproval;
use Modules\Pricing\Policies\PricingPolicy;
use Modules\Pricing\Services\MarginGuardrail;

/**
 * Margin override approvals: a supervisor authorizes a document whose margin is below the policy minimum.
 */
final class MarginApprovalController extends Controller
{
    public function __construct(private readonly MarginGuardrail $guardrail) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', MarginOverrideApproval::class);

        return PricingResource::collection(
            MarginOverrideApproval::query()
                ->when($request->filled('document_id'), static fn ($q) => $q->where('document_id', $request->query('document_id')))
                ->orderByDesc('approved_at')
                ->paginate(max(1, min(100, (int) $request->query('per_page', 25)))),
        );
    }

    /**
     * The margin of a stored document, for the guardrail badge before confirming it.
     */
    public function check(string $documentType, string $documentId): JsonResponse
    {
        $document = $this->document($documentType, $documentId);
        $evaluations = $this->guardrail->evaluateDocument($document);
        $summary = $this->guardrail->summarize($evaluations);
        $approved = MarginOverrideApproval::query()->where('document_type', $documentType)->where('document_id', $documentId)->exists();

        return response()->json(['data' => [...$summary, 'lines' => $evaluations->values(), 'has_approval' => $approved]]);
    }

    public function approve(MarginApprovalRequest $request): JsonResponse
    {
        $data = $request->validated();
        $document = $this->document($data['document_type'], $data['document_id']);
        $summary = $this->guardrail->summarize($this->guardrail->evaluateDocument($document));
        $evaluations = $this->guardrail->evaluateDocument($document);
        $role = $evaluations->firstWhere('status', MarginGuardrail::Blocked)['requires_approval_role'] ?? null;

        if (! (new PricingPolicy)->approveMargin($request->user(), $role)) {
            abort(403, 'Your role may not authorize a margin override.');
        }

        if ($summary['status'] !== MarginGuardrail::Blocked) {
            throw ValidationException::withMessages(['document_id' => ['The document is not below the margin threshold: no override is needed.']]);
        }

        $approval = MarginOverrideApproval::query()->create([
            'tenant_id' => (string) app(TenantContext::class)->getTenantId(),
            'document_type' => $data['document_type'],
            'document_id' => $data['document_id'],
            'calculated_margin_percent' => $summary['margin_percent'] ?? '0',
            'threshold_percent' => $summary['threshold_percent'] ?? '0',
            'approved_by_user_id' => (string) $request->user()->getKey(),
            'approved_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);

        return (new PricingResource($approval->refresh()))->response()->setStatusCode(201);
    }

    private function document(string $type, string $id): object
    {
        Gate::authorize('viewAny', MarginOverrideApproval::class);

        return match ($type) {
            'sales_quote' => SalesQuote::query()->with('items')->findOrFail($id),
            'sales_order' => SalesOrder::query()->with('items')->findOrFail($id),
            default => abort(404),
        };
    }
}
