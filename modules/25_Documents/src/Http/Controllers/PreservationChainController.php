<?php

declare(strict_types=1);

namespace Modules\Documents\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Documents\Http\Resources\PreservationChainLinkResource;
use Modules\Documents\Models\DmsPreservationChainLink;
use Modules\Documents\Services\DocumentAccess;
use Modules\Documents\Services\PreservationChainService;
use OpenApi\Attributes as OA;

/**
 * Hash chain over the closed preservation packages: anchor, list, verify, and the retention and sealing status.
 */
final class PreservationChainController extends Controller
{
    use ApiResponse;

    /**
     * Inject the collaborators.
     */
    public function __construct(private readonly DocumentAccess $access, private readonly PreservationChainService $chain) {}

    /**
     * List the chain links.
     */
    #[OA\Get(
        path: '/api/v1/dms/preservation-chain',
        operationId: 'listDmsPreservationChain',
        description: 'The links of the hash chain in order of sequence.',
        summary: 'List preservation chain links',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Preservation'],
        responses: [
            new OA\Response(response: 200, description: 'Links', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsPreservationChainLinkResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse(PreservationChainLinkResource::collection(DmsPreservationChainLink::query()->orderBy('sequence')->get()));
    }

    /**
     * Retention and sealing status.
     */
    #[OA\Get(
        path: '/api/v1/dms/preservation-chain/status',
        operationId: 'showDmsPreservationChainStatus',
        description: 'Closed preservation packages with their retention end, time-stamp and sealing status (`sealed` once chained, `awaiting_anchor` before).',
        summary: 'Preservation retention and sealing status',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Preservation'],
        responses: [
            new OA\Response(response: 200, description: 'Status', content: new OA\JsonContent(ref: '#/components/schemas/DmsPreservationChainStatus')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function status(): JsonResponse
    {
        return $this->successResponse($this->chain->status());
    }

    /**
     * Verify the chain.
     */
    #[OA\Get(
        path: '/api/v1/dms/preservation-chain/verify',
        operationId: 'verifyDmsPreservationChain',
        description: 'Recomputes every link and compares it with its package. `reason` is one of sequence_gap, previous_hash_mismatch, link_hash_mismatch, package_missing, package_hash_mismatch.',
        summary: 'Verify the preservation chain',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Preservation'],
        responses: [
            new OA\Response(response: 200, description: 'Verification result', content: new OA\JsonContent(ref: '#/components/schemas/DmsPreservationChainVerification')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function verify(): JsonResponse
    {
        return $this->successResponse($this->chain->verify());
    }

    /**
     * Anchor closed packages.
     */
    #[OA\Post(
        path: '/api/v1/dms/preservation-chain/anchor',
        operationId: 'anchorDmsPreservationChain',
        description: 'Append a link for every closed preservation package that has none, oldest closing first. Owners and administrators only; running it with nothing to anchor appends nothing.',
        summary: 'Anchor closed packages in the chain',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Document Preservation'],
        responses: [
            new OA\Response(response: 200, description: 'Links appended', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/DmsPreservationChainLinkResource'))])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function anchor(Request $request): JsonResponse
    {
        $this->access->assertManager($request->user());

        return $this->successResponse(PreservationChainLinkResource::collection(collect($this->chain->anchorClosedPackages($request->user()))), 'Packages anchored');
    }
}
