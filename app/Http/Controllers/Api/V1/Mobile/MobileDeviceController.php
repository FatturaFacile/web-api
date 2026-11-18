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
use App\Services\Auth\UserSessions;
use App\Services\Mobile\DeviceSessionService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MobileDeviceController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    public function __construct(private readonly DeviceSessionService $devices) {}

    /** Register the device the current token is used from (e.g. tokens issued by the classic login). */
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
        $tokenId = UserSessions::currentTokenId($user);

        $device = $this->devices->register($user, (string) app(TenantContext::class)->getTenantId(), $data, $tokenId === null ? null : (int) $tokenId);

        return $this->successResponse($device, 'Device registered', Response::HTTP_CREATED);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse(
            MobileDeviceSession::where('user_id', $request->user()->id)->orderByDesc('last_active_at')->get()
        );
    }

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
    public function wipeAck(Request $request, WipeRevokedMobileDeviceCacheAction $action): JsonResponse
    {
        $device = $this->currentDevice($request);
        if ($device === null) {
            return $this->problemDetailsResponse('Device Not Registered', 'Unknown device.', Response::HTTP_NOT_FOUND);
        }

        $action->execute($device);

        return $this->successResponse(null, 'Wipe acknowledged; session closed');
    }

    public function manifest(Request $request): JsonResponse
    {
        $device = $this->currentDevice($request);

        return $this->successResponse($device?->manifests()->get() ?? []);
    }

    /** The app reports what it holds offline so the server can plan incremental syncs. */
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
