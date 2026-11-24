<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\UpdateUserPreferencesRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Interface preferences of the signed-in user (theme, density, reduced motion), the same in every tenant.
 */
class UserPreferencesController extends Controller
{
    use ApiResponse;

    public const array Defaults = User::UiPreferenceDefaults;

    #[OA\Get(path: '/api/v1/user/preferences', operationId: 'getUserPreferences', description: 'Theme, density and reduced motion of the signed-in user; the defaults when nothing was saved. A null reduced_motion follows the operating system.', summary: 'Get my interface preferences', security: [['bearerAuth' => []]], tags: ['User'], responses: [
        new OA\Response(response: 200, description: 'Preferences retrieved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->successResponse(data: $this->preferencesOf($user), message: 'Preferences retrieved');
    }

    #[OA\Patch(path: '/api/v1/user/preferences', operationId: 'updateUserPreferences', description: 'Changes the given interface preferences of the signed-in user; the fields that are absent keep their value.', summary: 'Update my interface preferences', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(properties: [
        new OA\Property(property: 'theme_preference', type: 'string', enum: ['light', 'dark', 'system']),
        new OA\Property(property: 'ui_density', type: 'string', enum: ['compact', 'comfortable', 'spacious']),
        new OA\Property(property: 'reduced_motion', type: 'boolean', nullable: true),
    ])), tags: ['User'], responses: [
        new OA\Response(response: 200, description: 'Preferences saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiValidationErrorResponse')),
    ])]
    public function update(UpdateUserPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->forceFill(['ui_preferences' => [...$this->preferencesOf($user), ...$request->validated()]])->save();

        return $this->successResponse(data: $this->preferencesOf($user), message: 'Preferences saved');
    }

    /**
     * @return array{theme_preference: string, ui_density: string, reduced_motion: bool|null}
     */
    private function preferencesOf(User $user): array
    {
        return array_replace(self::Defaults, array_intersect_key((array) $user->ui_preferences, self::Defaults));
    }
}
