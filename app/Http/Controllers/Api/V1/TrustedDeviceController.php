<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\Auth\AuthProblem;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\TrustedDeviceResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Auth\RegistrationAudit;
use App\Services\Auth\TrustedDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/**
 * The devices the signed-in user chose to remember at the second-factor challenge: list and revoke.
 */
class TrustedDeviceController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TrustedDevices $trustedDevices,
        private readonly RegistrationAudit $audit,
    ) {}

    #[OA\Get(
        path: '/api/v1/auth/two-factor/trusted-devices',
        operationId: 'listTrustedDevices',
        description: 'The devices of the signed-in user that skip the second factor until they expire. Tokens are never returned.',
        summary: 'List trusted devices',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Trusted devices, newest use first', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(
            data: TrustedDeviceResource::collection($this->trustedDevices->list($user)),
            meta: ['days' => $this->trustedDevices->days(), 'enabled' => $this->trustedDevices->enabled()],
        );
    }

    #[OA\Delete(
        path: '/api/v1/auth/two-factor/trusted-devices/{id}',
        operationId: 'revokeTrustedDevice',
        description: 'Removes one trusted device: the next login from it asks for the second factor again.',
        summary: 'Revoke a trusted device',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Device removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Not a device of the user', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->trustedDevices->revoke($user, $id)) {
            return $this->problemDetailsResponse(
                title: __('two_factor.errors.trusted_device_not_found_title'),
                detail: __('two_factor.errors.trusted_device_not_found_detail'),
                status: Response::HTTP_NOT_FOUND,
                type: AuthProblem::TrustedDeviceNotFound->value,
            );
        }

        $this->audit->record(RegistrationAudit::TRUSTED_DEVICE_REVOKED, (string) $user->id, ['device_id' => $id]);

        return $this->successResponse(message: __('two_factor.messages.trusted_device_revoked'));
    }

    #[OA\Delete(
        path: '/api/v1/auth/two-factor/trusted-devices',
        operationId: 'revokeAllTrustedDevices',
        description: 'Removes every trusted device of the signed-in user.',
        summary: 'Revoke all trusted devices',
        security: [['bearerAuth' => []]],
        tags: ['Authentication'],
        responses: [
            new OA\Response(response: 200, description: 'Number of devices removed', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $revoked = $this->trustedDevices->revokeAll($user);

        $this->audit->record(RegistrationAudit::TRUSTED_DEVICES_REVOKED, (string) $user->id, ['revoked' => $revoked, 'scope' => 'user']);

        return $this->successResponse(data: ['revoked' => $revoked], message: __('two_factor.messages.trusted_devices_revoked'));
    }
}
