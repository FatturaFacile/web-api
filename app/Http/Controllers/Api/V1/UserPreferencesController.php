<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Ui\UpdateUserPreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ui\UpdateUserPreferencesRequest;
use App\Http\Resources\UserPreferencesResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class UserPreferencesController extends Controller
{
    use ApiResponse;

    #[OA\Get(
        path: '/api/v1/user/preferences',
        operationId: 'getUserPreferences',
        description: 'Interface preferences of the signed-in user: theme_preference, ui_density and reduced_motion.',
        summary: 'Get user interface preferences',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Users'],
        responses: [
            new OA\Response(response: 200, description: 'Preferences retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        ]
    )]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(
            UserPreferencesResource::make($user)->resolve(),
            __('settings.messages.ui_preferences_retrieved')
        );
    }

    #[OA\Patch(
        path: '/api/v1/user/preferences',
        operationId: 'updateUserPreferences',
        description: 'Partial update of the interface preferences of the signed-in user: theme_preference (light, dark, system), ui_density (compact, comfortable, spacious), reduced_motion (boolean or null to follow the system).',
        summary: 'Update user interface preferences',
        security: [['bearerAuth' => []]],
        tags: ['Settings', 'Users'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(properties: [
                new OA\Property(property: 'theme_preference', type: 'string', enum: ['light', 'dark', 'system']),
                new OA\Property(property: 'ui_density', type: 'string', enum: ['compact', 'comfortable', 'spacious']),
                new OA\Property(property: 'reduced_motion', type: 'boolean', nullable: true),
            ])
        ),
        responses: [
            new OA\Response(response: 200, description: 'Preferences updated', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
        ]
    )]
    public function update(UpdateUserPreferencesRequest $request, UpdateUserPreferences $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(
            UserPreferencesResource::make($action->execute($user, $request->validated()))->resolve(),
            __('settings.messages.ui_preferences_updated')
        );
    }
}
