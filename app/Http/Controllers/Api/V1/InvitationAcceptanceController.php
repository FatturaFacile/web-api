<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SodConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\AcceptInvitationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\AccessInvitations;
use DomainException;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public link of an invitation e-mail: the token is the credential.
 */
class InvitationAcceptanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly AccessInvitations $invitations) {}

    #[OA\Get(path: '/api/v1/auth/invitations/{token}', operationId: 'previewInvitation', description: 'Who invited the address, to which company and whether the address already has an account. A token that is unknown, used, revoked or expired answers 404.', summary: 'Preview an invitation', tags: ['Auth'], parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Invitation found', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 404, description: 'Invitation not valid', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(string $token): JsonResponse
    {
        $preview = $this->invitations->preview($token);

        if ($preview === null) {
            return $this->problemDetailsResponse(title: 'Invitation Not Found', detail: __('access.errors.invitation_invalid'), status: Response::HTTP_NOT_FOUND);
        }

        return $this->successResponse(data: $preview, message: 'Invitation retrieved');
    }

    #[OA\Post(path: '/api/v1/auth/invitations/{token}/accept', operationId: 'acceptInvitation', description: 'An address without an account sends a name and a password and the account is created. Whoever already has an account sends nothing and must be signed in with it.', summary: 'Accept an invitation', tags: ['Auth'], requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'name', type: 'string'),
        new OA\Property(property: 'password', type: 'string', format: 'password'),
        new OA\Property(property: 'password_confirmation', type: 'string', format: 'password'),
    ])), parameters: [new OA\Parameter(name: 'token', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [
        new OA\Response(response: 200, description: 'Invitation accepted', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 401, description: 'The account of the address must sign in first', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 404, description: 'Invitation not valid', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function accept(AcceptInvitationRequest $request, string $token): JsonResponse
    {
        $preview = $this->invitations->preview($token);

        if ($preview === null) {
            return $this->problemDetailsResponse(title: 'Invitation Not Found', detail: __('access.errors.invitation_invalid'), status: Response::HTTP_NOT_FOUND);
        }

        $session = $request->sessionUser();

        if ($preview['has_account'] && $session === null) {
            return $this->problemDetailsResponse(title: 'Sign In Required', detail: __('access.errors.invitation_sign_in'), status: Response::HTTP_UNAUTHORIZED);
        }

        try {
            $result = $this->invitations->accept($token, $session, $request->safe()->only(['name', 'password']));
        } catch (SodConflictException $exception) {
            return $this->problemDetailsResponse(title: 'Segregation of Duties Conflict', detail: $exception->getMessage(), status: Response::HTTP_CONFLICT);
        } catch (DomainException $exception) {
            return $this->problemDetailsResponse(title: 'Invitation Refused', detail: $exception->getMessage(), status: Response::HTTP_FORBIDDEN);
        }

        return $this->successResponse(data: $result, message: 'Invitation accepted');
    }
}
