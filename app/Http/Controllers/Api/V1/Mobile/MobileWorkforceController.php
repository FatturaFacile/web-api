<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Http\Controllers\Api\V1\Mobile\Concerns\ResolvesMobileContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\EmployeeShift;
use App\Models\WorkSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

/** Geofenced work sites and the employee shift calendar. */
class MobileWorkforceController extends Controller
{
    use ApiResponse, ResolvesMobileContext;

    #[OA\Get(
        path: '/api/v1/mobile/work-sites',
        operationId: 'listMobileWorkSites',
        description: 'Active geofenced work sites of the tenant, by name.',
        summary: 'List work sites',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        responses: [
            new OA\Response(response: 200, description: 'Work sites', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function workSites(): JsonResponse
    {
        return $this->successResponse(WorkSite::where('is_active', true)->orderBy('name')->get());
    }

    #[OA\Post(
        path: '/api/v1/mobile/work-sites',
        operationId: 'createMobileWorkSite',
        description: 'Requires the manage-work-sites ability.',
        summary: 'Create work site',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['name', 'latitude', 'longitude'], properties: [new OA\Property(property: 'name', type: 'string', maxLength: 150), new OA\Property(property: 'latitude', type: 'number', minimum: -90, maximum: 90), new OA\Property(property: 'longitude', type: 'number', minimum: -180, maximum: 180), new OA\Property(property: 'radius_meters', type: 'integer', minimum: 10, maximum: 5000, nullable: true), new OA\Property(property: 'is_active', type: 'boolean', nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Work site created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeWorkSite(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-work-sites');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['nullable', 'integer', 'min:10', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        return $this->successResponse(WorkSite::create($data), 'Work site created', Response::HTTP_CREATED);
    }

    #[OA\Put(
        path: '/api/v1/mobile/work-sites/{id}',
        operationId: 'updateMobileWorkSite',
        description: 'Partial update of a work site; requires the manage-work-sites ability.',
        summary: 'Update work site',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the work site', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'name', type: 'string', maxLength: 150), new OA\Property(property: 'latitude', type: 'number', minimum: -90, maximum: 90), new OA\Property(property: 'longitude', type: 'number', minimum: -180, maximum: 180), new OA\Property(property: 'radius_meters', type: 'integer', minimum: 10, maximum: 5000, nullable: true), new OA\Property(property: 'is_active', type: 'boolean', nullable: true)])),
        responses: [
            new OA\Response(response: 200, description: 'Work site updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function updateWorkSite(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-work-sites');

        $site = WorkSite::findOrFail($id);
        $site->update($request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'radius_meters' => ['sometimes', 'integer', 'min:10', 'max:5000'],
            'is_active' => ['sometimes', 'boolean'],
        ]));

        return $this->successResponse($site->refresh(), 'Work site updated');
    }

    #[OA\Delete(
        path: '/api/v1/mobile/work-sites/{id}',
        operationId: 'deleteMobileWorkSite',
        description: 'Requires the manage-work-sites ability.',
        summary: 'Delete work site',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the work site', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Work site deleted', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', nullable: true), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyWorkSite(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-work-sites');
        WorkSite::findOrFail($id)->delete();

        return $this->successResponse(null, 'Work site deleted');
    }

    /** Own shift calendar; managers can query another user with `user_id`. */
    #[OA\Get(
        path: '/api/v1/mobile/shifts',
        operationId: 'listMobileShifts',
        description: 'Own shift calendar (default: this week plus three weeks); another user can be queried with user_id when the view-all-attendance ability is held.',
        summary: 'List shifts',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'from', in: 'query', required: false, description: 'Start of the window', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', required: false, description: 'End of the window (not before from)', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'user_id', in: 'query', required: false, description: 'Query the shifts of another user', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Shifts', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object')), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function shifts(Request $request): JsonResponse
    {
        $params = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', 'uuid'],
        ]);

        $userId = $request->user()->id;
        if (! empty($params['user_id']) && $params['user_id'] !== $userId) {
            $this->authorizeAdmin($request, 'view-all-attendance');
            $userId = $params['user_id'];
        }

        $shifts = EmployeeShift::with('workSite:id,name')
            ->where('user_id', $userId)
            ->where('ends_at', '>=', $params['from'] ?? now()->startOfWeek())
            ->where('starts_at', '<=', $params['to'] ?? now()->endOfWeek()->addWeeks(3))
            ->orderBy('starts_at')
            ->get();

        return $this->successResponse($shifts);
    }

    #[OA\Post(
        path: '/api/v1/mobile/shifts',
        operationId: 'createMobileShift',
        description: 'Requires the manage-shifts ability.',
        summary: 'Create shift',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['user_id', 'starts_at', 'ends_at'], properties: [new OA\Property(property: 'user_id', type: 'string', format: 'uuid'), new OA\Property(property: 'work_site_id', type: 'string', format: 'uuid', nullable: true), new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'), new OA\Property(property: 'shift_type', type: 'string', enum: ['regular', 'night', 'on_call', 'holiday'], nullable: true), new OA\Property(property: 'notes', type: 'string', maxLength: 500, nullable: true)])),
        responses: [
            new OA\Response(response: 201, description: 'Shift created', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object'), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function storeShift(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-shifts');

        $data = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
            'work_site_id' => ['nullable', 'uuid', 'exists:work_sites,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'shift_type' => ['nullable', 'in:regular,night,on_call,holiday'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = EmployeeShift::create($data + ['shift_type' => 'regular']);

        return $this->successResponse($shift->load('workSite:id,name'), 'Shift created', Response::HTTP_CREATED);
    }

    #[OA\Delete(
        path: '/api/v1/mobile/shifts/{id}',
        operationId: 'deleteMobileShift',
        description: 'Requires the manage-shifts ability.',
        summary: 'Delete shift',
        security: [['bearerAuth' => [], 'tenantHeader' => []]],
        tags: ['Mobile'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, description: 'Identifier of the shift', schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [
            new OA\Response(response: 200, description: 'Shift deleted', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'success', type: 'boolean'), new OA\Property(property: 'message', type: 'string'), new OA\Property(property: 'data', type: 'object', nullable: true), new OA\Property(property: 'meta', type: 'object')])),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Resource not found', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function destroyShift(Request $request, string $id): JsonResponse
    {
        $this->authorizeAdmin($request, 'manage-shifts');
        EmployeeShift::findOrFail($id)->delete();

        return $this->successResponse(null, 'Shift deleted');
    }
}
