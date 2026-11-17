<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Actions\Mobile\RegisterMobileDevicePushTokenAction;
use App\Actions\Mobile\WipeRevokedMobileDeviceCacheAction;
use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MobileDeviceSession;
use App\Models\MobileOfflineCacheManifest;
use App\Models\User;
use App\Services\Mobile\DeviceSessionService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class MobileDeviceController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    public function __construct(private readonly DeviceSessionService $devices) {}

    /** Register the device the current token is used from (e.g. tokens issued by the classic login). */
    #[OA\Post(
        path: '/api/v1/mobile/devices',
        operationId: 'registerMobileDevice',
        description: 'Registers the device the current token is used from (for tokens issued by the classic login).',
        summary: 'Register mobile device',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['device_id', 'platform'], properties: [new OA\Property(property: 'device_id', type: 'string', maxLength: 191), new OA\Property(property: 'device_name', type: 'string', maxLength: 191, nullable: true), new OA\Property(property: 'platform', type: 'string', enum: ['ios', 'android', 'android_rugged_zebra']), new OA\Property(property: 'app_version', type: 'string', maxLength: 30, nullable: true), new OA\Property(property: 'biometric_enrolled', type: 'boolean', nullable: true), new OA\Property(property: 'push_token', type: 'string', nullable: true), new OA\Property(property: 'push_provider', type: 'string', enum: ['fcm', 'apns'], nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Device registered', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_id' => ['required', 'string', 'max:191'],
            'device_name' => ['nullable', 'string', 'max:191'],
            'platform' => ['required', 'in:'.implode(',', config('mobile.platforms'))],
            'app_version' => ['nullable', 'string', 'max:30'],
            'biometric_enrolled' => ['nullable', 'boolean'],
            'push_token' => ['nullable', 'string'],
            'push_provider' => ['nullable', 'in:fcm,apns'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $tokenId = $user->currentAccessToken()?->getKey();

        $device = $this->devices->register($user, (string) app(TenantContext::class)->getTenantId(), $data, $tokenId === null ? null : (int) $tokenId);

        return $this->successResponse($device, 'Device registered', Response::HTTP_CREATED);
    }

    #[OA\Get(
        path: '/api/v1/mobile/devices',
        operationId: 'listMobileDevices',
        description: 'Devices registered by the authenticated user, most recently active first.',
        summary: 'List my mobile devices',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        responses: [
            new OA\Response(response: 200, description: 'Device sessions of the user', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            MobileDeviceSession::where('user_id', $request->user()->id)->orderByDesc('last_active_at')->get()
        );
    }

    #[OA\Put(
        path: '/api/v1/mobile/devices/current/push-token',
        operationId: 'updateMobileDevicePushToken',
        description: 'Stores the FCM/APNs push token of the current device.',
        summary: 'Update push token',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['push_token'], properties: [new OA\Property(property: 'push_token', type: 'string'), new OA\Property(property: 'push_provider', type: 'string', enum: ['fcm', 'apns'], nullable: true), new OA\Property(property: 'app_version', type: 'string', maxLength: 30, nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Push token registered', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 428, description: 'Device not registered', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function updatePushToken(Request $request, RegisterMobileDevicePushTokenAction $action): JsonResponse
    {
        $data = $request->validate([
            'push_token' => ['required', 'string'],
            'push_provider' => ['nullable', 'in:fcm,apns'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ]);

        $device = $this->currentDevice($request);
        if ($device === null) {
            return $this->problemDetailsResponse('Device Not Registered', 'Register the device first (POST /mobile/devices).', Response::HTTP_PRECONDITION_REQUIRED);
        }

        return $this->successResponse(
            $action->execute($device, $data['push_token'], $data['push_provider'] ?? null, $data['app_version'] ?? null),
            'Push token registered'
        );
    }

    #[OA\Put(
        path: '/api/v1/mobile/devices/current/biometric',
        operationId: 'updateMobileDeviceBiometric',
        description: 'Records whether biometric unlock is enrolled on the current device.',
        summary: 'Update biometric status',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['biometric_enrolled'], properties: [new OA\Property(property: 'biometric_enrolled', type: 'boolean')])),
        responses: [
            new OA\Response(response: 200, description: 'Biometric status updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 428, description: 'Device not registered', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function updateBiometric(Request $request): JsonResponse
    {
        $data = $request->validate(['biometric_enrolled' => ['required', 'boolean']]);

        $device = $this->currentDevice($request);
        if ($device === null) {
            return $this->problemDetailsResponse('Device Not Registered', 'Register the device first (POST /mobile/devices).', Response::HTTP_PRECONDITION_REQUIRED);
        }

        $device->forceFill(['biometric_enrolled' => $data['biometric_enrolled']])->save();

        return $this->successResponse($device, 'Biometric status updated');
    }

    /** Lost phone: the owner revokes one of their devices; administrators can revoke any device. */
    #[OA\Delete(
        path: '/api/v1/mobile/devices/{id}',
        operationId: 'revokeMobileDevice',
        description: 'Lost phone: the owner revokes one of their devices; administrators with the manage-mobile-devices ability can revoke any device. A local cache wipe is requested.',
        summary: 'Revoke mobile device',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the device session', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Device revoked', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function revoke(Request $request, string $id): JsonResponse
    {
        $device = MobileDeviceSession::findOrFail($id);

        if ($device->user_id !== $request->user()->id) {
            $this->authorizeAdmin($request, 'manage-mobile-devices');
        }

        $reason = $device->user_id === $request->user()->id ? 'lost_or_stolen' : 'admin_revoked';

        return $this->successResponse($this->devices->revoke($device, $reason), 'Device revoked; local cache wipe requested');
    }

    /** Administrators: list the devices of the tenant (optionally one user). */
    #[OA\Get(
        path: '/api/v1/mobile/admin/devices',
        operationId: 'listMobileAdminDevices',
        description: 'Administrators: device sessions of the tenant, optionally of one user (paginated, 50 per page).',
        summary: 'List tenant mobile devices',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'user_id', in: 'query', required: false, description: 'Only the devices of this user', schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Page number', schema: new OA\Schema(type: 'integer'))],
        responses: [
            new OA\Response(response: 200, description: 'Paginated device sessions', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function adminIndex(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-mobile-devices');

        return $this->successResponse(
            MobileDeviceSession::with('user:id,name,email')
                ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->query('user_id')))
                ->orderByDesc('last_active_at')->paginate(50)
        );
    }

    /** Called by a revoked device once its local Drift/SQLite cache has been erased. */
    #[OA\Post(
        path: '/api/v1/mobile/devices/current/wipe-ack',
        operationId: 'acknowledgeMobileDeviceWipe',
        description: 'Called by a revoked device once its local offline cache has been erased; the device session is closed.',
        summary: 'Acknowledge remote wipe',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        responses: [
            new OA\Response(response: 200, description: 'Wipe acknowledged', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', nullable: true), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function wipeAck(Request $request, WipeRevokedMobileDeviceCacheAction $action): JsonResponse
    {
        $device = $this->currentDevice($request);
        if ($device === null) {
            return $this->problemDetailsResponse('Device Not Registered', 'Unknown device.', Response::HTTP_NOT_FOUND);
        }

        $action->execute($device);

        return $this->successResponse(null, 'Wipe acknowledged; session closed');
    }

    #[OA\Get(
        path: '/api/v1/mobile/devices/current/manifest',
        operationId: 'getMobileDeviceManifest',
        description: 'What the current device reports to hold offline, per entity.',
        summary: 'Get offline cache manifest',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        responses: [
            new OA\Response(response: 200, description: 'Manifest entries', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function manifest(Request $request): JsonResponse
    {
        $device = $this->currentDevice($request);

        return $this->successResponse($device?->manifests()->get() ?? []);
    }

    /** The app reports what it holds offline so the server can plan incremental syncs. */
    #[OA\Put(
        path: '/api/v1/mobile/devices/current/manifest',
        operationId: 'updateMobileDeviceManifest',
        description: 'The app reports what it holds offline so the server can plan incremental syncs.',
        summary: 'Update offline cache manifest',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['entities'], properties: [new OA\Property(property: 'entities', type: 'array', minItems: 1, items: new OA\Items(type: 'object', required: ['entity_name', 'record_count', 'storage_bytes'], properties: [new OA\Property(property: 'entity_name', type: 'string', maxLength: 60), new OA\Property(property: 'last_synced_at', type: 'string', format: 'date-time', nullable: true), new OA\Property(property: 'record_count', type: 'integer', minimum: 0), new OA\Property(property: 'storage_bytes', type: 'integer', minimum: 0)]))])),
        responses: [
            new OA\Response(response: 200, description: 'Manifest updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
            new OA\Response(response: 428, description: 'Device not registered', content: new OA\JsonContent(ref: '#/components/schemas/ApiProblemDetailsResponse')),
        ]
    )]
    public function updateManifest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entities' => ['required', 'array', 'min:1'],
            'entities.*.entity_name' => ['required', 'string', 'max:60'],
            'entities.*.last_synced_at' => ['nullable', 'date'],
            'entities.*.record_count' => ['required', 'integer', 'min:0'],
            'entities.*.storage_bytes' => ['required', 'integer', 'min:0'],
        ]);

        $device = $this->currentDevice($request);
        if ($device === null) {
            return $this->problemDetailsResponse('Device Not Registered', 'Register the device first (POST /mobile/devices).', Response::HTTP_PRECONDITION_REQUIRED);
        }

        foreach ($data['entities'] as $entity) {
            MobileOfflineCacheManifest::updateOrCreate(
                ['mobile_device_session_id' => $device->id, 'entity_name' => $entity['entity_name']],
                [
                    'tenant_id' => $device->tenant_id,
                    'last_synced_at' => $entity['last_synced_at'] ?? now(),
                    'record_count' => $entity['record_count'],
                    'storage_bytes' => $entity['storage_bytes'],
                ]
            );
        }

        return $this->successResponse($device->manifests()->get(), 'Manifest updated');
    }
}
