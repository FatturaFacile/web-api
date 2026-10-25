<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateTenantPreferencesRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Tenancy\TenantPreferences;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class TenantPreferencesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TenantPreferences $preferences) {}

    #[OA\Get(
        path: '/api/v1/settings/preferences',
        operationId: 'getTenantPreferences',
        description: 'Get all tenant preference settings grouped by domain.',
        summary: 'Get tenant preferences',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Tenants'],
        responses: [
            new OA\Response(response: 200, description: 'Preferences retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        return $this->successResponse($this->preferences->all(), __('settings.messages.preferences_retrieved'));
    }

    #[OA\Put(
        path: '/api/v1/settings/preferences/{group}',
        operationId: 'updateTenantPreferences',
        description: 'Update a specific group of tenant preferences.',
        summary: 'Update tenant preference group',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Tenants'],
        parameters: [
            new OA\Parameter(name: 'group', in: 'path', required: true, description: 'Preference group name (e.g. sdi, qr_bill, general)', schema: new OA\Schema(type: 'string')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent
        ),
        responses: [
            new OA\Response(response: 200, description: 'Preferences updated successfully', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 404, description: 'Unknown preference group', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateTenantPreferencesRequest $request, string $group): JsonResponse
    {
        abort_unless(in_array($group, TenantPreferences::GROUPS, true), 404, __('sdi.unknown_preference_group'));

        // Only keys defined for the group are validated and kept; null clears a preference.
        return $this->successResponse(
            $this->preferences->update($group, $request->validated()),
            __('settings.messages.preferences_updated')
        );
    }
}
