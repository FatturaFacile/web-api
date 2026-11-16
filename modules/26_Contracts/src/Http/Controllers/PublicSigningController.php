<?php

declare(strict_types=1);

namespace Modules\Contracts\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Contracts\Http\Requests\PublicDeclineSigningRequest;
use Modules\Contracts\Services\PublicSigningService;
use OpenApi\Attributes as OA;

/**
 * Signing page without an account: the secret token of the link identifies the signer, the request and the company.
 */
final class PublicSigningController extends Controller
{
    public function __construct(private readonly PublicSigningService $signing) {}

    #[OA\Get(path: '/api/v1/public/contract-signing/{token}', operationId: 'showPublicContractSigning', description: 'The text to sign, the signers and whether it is the turn of the holder of the link. No authentication: the token is the credential and is stored hashed.', summary: 'Show a signing link', security: [], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Signing view', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractPublicSigningView')])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 429, description: 'Too many attempts'),
    ])]
    public function show(string $token): JsonResponse
    {
        return response()->json(['data' => $this->signing->view($token)]);
    }

    #[OA\Post(path: '/api/v1/public/contract-signing/{token}/sign', operationId: 'signPublicContractSigning', description: 'The holder of the link signs the text; the last signature completes the request.', summary: 'Sign through a link', security: [], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Signed', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractPublicSigningView')])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Not open, expired, already signed or not the turn', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        new OA\Response(response: 429, description: 'Too many attempts'),
    ])]
    public function sign(Request $request, string $token): JsonResponse
    {
        $this->signing->sign($token, $request->ip());

        return response()->json(['data' => $this->signing->view($token)]);
    }

    #[OA\Post(path: '/api/v1/public/contract-signing/{token}/decline', operationId: 'declinePublicContractSigning', description: 'The holder of the link refuses to sign, with a reason; the request ends declined.', summary: 'Decline through a link', security: [], tags: ['Contract Authoring'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/ContractPublicSigningDeclineRequest')), responses: [
        new OA\Response(response: 200, description: 'Declined', content: new OA\JsonContent(properties: [new OA\Property(property: 'data', ref: '#/components/schemas/ContractPublicSigningView')])),
        new OA\Response(response: 404, description: 'Invalid link', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Not open, expired or already signed', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        new OA\Response(response: 429, description: 'Too many attempts'),
    ])]
    public function decline(PublicDeclineSigningRequest $request, string $token): JsonResponse
    {
        $this->signing->decline($token, $request->validated('reason'));

        return response()->json(['data' => $this->signing->view($token)]);
    }
}
