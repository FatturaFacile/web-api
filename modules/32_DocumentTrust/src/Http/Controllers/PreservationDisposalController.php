<?php

declare(strict_types=1);

namespace Modules\DocumentTrust\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Modules\DocumentTrust\Exceptions\DocumentTrustException;
use Modules\DocumentTrust\Models\PreservationDisposal;
use Modules\DocumentTrust\Models\PreservationDisposalProposal;
use Modules\DocumentTrust\Models\PreservationPackage;
use Modules\DocumentTrust\Services\PreservationDisposalService;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * Disposal (scarto) of closed preservation packages after their retention, with a two-person rule. Owners and
 * administrators only. A technical control, not legal advice: the accountant confirms whether documents may be destroyed.
 */
final class PreservationDisposalController extends Controller
{
    use ApiResponse;

    /**
     * Inject the disposal service.
     */
    public function __construct(private readonly PreservationDisposalService $disposals) {}

    #[OA\Get(path: '/api/v1/document-trust/preservation-disposal-candidates', operationId: 'listPreservationDisposalCandidates', description: 'Closed packages not yet disposed of, with the computed expiry (the longer of the retention fixed at closing and the current tenant setting counted from the closing date) and what blocks disposal: retention_not_expired, retention_not_confirmed (the accountant has not confirmed the retention periods), legal_hold, proposal_open. For owners and administrators.', summary: 'List disposal candidates', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [
        new OA\Parameter(name: 'only_eligible', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
    ], responses: [
        new OA\Response(response: 200, description: 'The packages with their assessment', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * List the packages and what blocks their disposal.
     */
    public function candidates(Request $request): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);

        return $this->successResponse($this->disposals->candidates($request->boolean('only_eligible'))->map(fn (array $row): array => [
            'package_id' => (string) $row['package']->id,
            'name' => $row['package']->name,
            'document_class' => $row['package']->document_class,
            'items_count' => (int) $row['package']->items_count,
            'closed_at' => $row['package']->closed_at?->toIso8601String(),
            'retention_until' => $row['package']->retention_until?->toDateString(),
            'expiry_date' => $row['assessment']['expiry_date']->toDateString(),
            'expired' => $row['assessment']['expired'],
            'eligible' => $row['assessment']['eligible'],
            'blockers' => $row['assessment']['blockers'],
        ])->all(), 'Disposal candidates retrieved');
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-packages/{id}/legal-hold', operationId: 'placePreservationLegalHold', description: 'Puts a legal hold on a closed package: while it is active the package cannot be proposed or disposed of. For owners and administrators.', summary: 'Place a legal hold on a package', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['reason'], properties: [new OA\Property(property: 'reason', type: 'string')])), tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 201, description: 'Hold placed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Package not closed, or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Place a legal hold.
     */
    public function placeHold(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $package = PreservationPackage::findOrFail($id);

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->disposals->placeHold($package, $data['reason'], $request->user()), 'Legal hold placed', Response::HTTP_CREATED));
    }

    #[OA\Delete(path: '/api/v1/document-trust/preservation-packages/{id}/legal-hold', operationId: 'releasePreservationLegalHold', description: 'Releases the active legal holds of a package. For owners and administrators.', summary: 'Release the legal hold of a package', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Hold released', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * Release the legal hold.
     */
    public function releaseHold(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);

        return $this->successResponse(['released' => $this->disposals->releaseHold(PreservationPackage::findOrFail($id), $request->user())], 'Legal hold released');
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation-disposal-proposals', operationId: 'listPreservationDisposalProposals', description: 'Disposal proposals of the tenant, newest first. For owners and administrators.', summary: 'List disposal proposals', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [
        new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'rejected', 'executed'])),
    ], responses: [
        new OA\Response(response: 200, description: 'The proposals', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * List the proposals.
     */
    public function proposals(Request $request): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $status = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,executed']])['status'] ?? null;

        return $this->successResponse(PreservationDisposalProposal::when($status, fn ($query, string $value) => $query->where('status', $value))->latest('proposed_at')->get()->map(fn (PreservationDisposalProposal $proposal): array => $this->proposal($proposal))->all(), 'Disposal proposals retrieved');
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-disposal-proposals', operationId: 'createPreservationDisposalProposal', description: 'Proposes the disposal of an eligible package (retention expired, retention confirmed by the accountant, no legal hold, no open proposal). A different owner or administrator must approve it. For owners and administrators.', summary: 'Propose a disposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(required: ['package_id'], properties: [new OA\Property(property: 'package_id', type: 'string', format: 'uuid'), new OA\Property(property: 'note', type: 'string', nullable: true)])), tags: ['Document Trust'], responses: [
        new OA\Response(response: 201, description: 'Proposal created', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Package not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'The package is not eligible (the message lists the blockers)', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Propose a disposal.
     */
    public function propose(Request $request): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $data = $request->validate(['package_id' => ['required', 'uuid'], 'note' => ['nullable', 'string', 'max:500']]);
        $package = PreservationPackage::findOrFail($data['package_id']);

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->proposal($this->disposals->propose($package, $data['note'] ?? null, $request->user())), 'Disposal proposed', Response::HTTP_CREATED));
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-disposal-proposals/{id}/approve', operationId: 'approvePreservationDisposalProposal', description: 'Approves a pending proposal. The approver must be an owner or administrator other than the proposer. For owners and administrators.', summary: 'Approve a disposal proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'note', type: 'string', nullable: true)])), tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Approved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Same person as the proposer, or already decided', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Approve a proposal.
     */
    public function approve(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $proposal = PreservationDisposalProposal::findOrFail($id);

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->proposal($this->disposals->approve($proposal, $note, $request->user())), 'Disposal approved'));
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-disposal-proposals/{id}/reject', operationId: 'rejectPreservationDisposalProposal', description: 'Rejects a pending proposal. Must be another owner or administrator than the proposer. For owners and administrators.', summary: 'Reject a disposal proposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [new OA\Property(property: 'note', type: 'string', nullable: true)])), tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'Rejected', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Same person as the proposer, or already decided', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Reject a proposal.
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $note = $request->validate(['note' => ['nullable', 'string', 'max:500']])['note'] ?? null;
        $proposal = PreservationDisposalProposal::findOrFail($id);

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->proposal($this->disposals->reject($proposal, $note, $request->user())), 'Disposal rejected'));
    }

    #[OA\Post(path: '/api/v1/document-trust/preservation-disposal-proposals/{id}/execute', operationId: 'executePreservationDisposalProposal', description: 'Executes an approved proposal: re-checks every blocker, deletes the preserved files from the storage and writes the irrevocable disposal record (who proposed, approved and executed, when, the manifest and its SHA-256). The manifest, the item records and the audit trail stay. IRREVERSIBLE. For owners and administrators.', summary: 'Execute a disposal', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))], responses: [
        new OA\Response(response: 200, description: 'The disposal record', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Not found in the active tenant', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Not approved, blocked now, or the manifest was tampered with', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    /**
     * Execute a disposal.
     */
    public function execute(Request $request, string $id): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);
        $proposal = PreservationDisposalProposal::findOrFail($id);

        return $this->guarded(fn (): JsonResponse => $this->successResponse($this->disposal($this->disposals->execute($proposal, $request->user())), 'Disposal executed'));
    }

    #[OA\Get(path: '/api/v1/document-trust/preservation-disposals', operationId: 'listPreservationDisposals', description: 'The irrevocable disposal records of the tenant: what was disposed of, by whom, when, with the SHA-256 of the manifest kept as proof. For owners and administrators.', summary: 'List disposal records', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Document Trust'], responses: [
        new OA\Response(response: 200, description: 'The records', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    /**
     * List the disposal records.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('manage', PreservationPackage::class);

        return $this->successResponse(PreservationDisposal::latest('executed_at')->get()->map(fn (PreservationDisposal $disposal): array => $this->disposal($disposal))->all(), 'Disposal records retrieved');
    }

    /**
     * @return array<string, mixed>
     */
    private function proposal(PreservationDisposalProposal $proposal): array
    {
        return [
            'id' => (string) $proposal->id, 'package_id' => (string) $proposal->package_id, 'status' => $proposal->status,
            'expiry_date' => $proposal->expiry_date?->toDateString(), 'note' => $proposal->note,
            'proposed_by' => $proposal->proposed_by, 'proposed_at' => $proposal->proposed_at?->toIso8601String(),
            'decided_by' => $proposal->decided_by, 'decided_at' => $proposal->decided_at?->toIso8601String(), 'decision_note' => $proposal->decision_note,
            'executed_by' => $proposal->executed_by, 'executed_at' => $proposal->executed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function disposal(PreservationDisposal $disposal): array
    {
        return [
            'id' => (string) $disposal->id, 'package_id' => (string) $disposal->package_id, 'proposal_id' => (string) $disposal->proposal_id,
            'document_class' => $disposal->document_class, 'package_name' => $disposal->package_name, 'items_count' => (int) $disposal->items_count,
            'total_bytes' => (int) $disposal->total_bytes, 'retention_until' => $disposal->retention_until?->toDateString(), 'expiry_date' => $disposal->expiry_date?->toDateString(),
            'manifest_sha256' => $disposal->manifest_sha256, 'files' => $disposal->files,
            'proposed_by' => $disposal->proposed_by, 'approved_by' => $disposal->approved_by, 'executed_by' => $disposal->executed_by,
            'executed_at' => $disposal->executed_at?->toIso8601String(),
        ];
    }

    /**
     * @param  callable(): JsonResponse  $operation
     */
    private function guarded(callable $operation): JsonResponse
    {
        try {
            return $operation();
        } catch (DocumentTrustException $exception) {
            throw ValidationException::withMessages(['disposal' => $exception->getMessage()]);
        }
    }
}
