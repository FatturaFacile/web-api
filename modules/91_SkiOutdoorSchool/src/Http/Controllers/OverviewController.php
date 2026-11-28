<?php

declare(strict_types=1);

namespace Modules\SkiOutdoorSchool\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\SkiOutdoorSchool\Application\Queries\OverviewQueries;
use Modules\SkiOutdoorSchool\Application\Services\SchoolSettingsService;
use Modules\SkiOutdoorSchool\Http\Controllers\Concerns\InteractsWithSkiSchool;
use Modules\SkiOutdoorSchool\Http\Requests\SaveSettingsRequest;
use Modules\SkiOutdoorSchool\Models\SchoolSetting;
use OpenApi\Attributes as OA;

/**
 * Dashboard figures, reference catalogues and the settings of the school (public booking portal, jurisdiction, default retention).
 */
final class OverviewController extends Controller
{
    use ApiResponse;
    use InteractsWithSkiSchool;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/ski-school/dashboard/stats', operationId: 'skiSchoolDashboardStats', summary: 'Figures of the school dashboard', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Figures')])]
    public function stats(OverviewQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SchoolSetting::class);

        return $this->successResponse($queries->stats(), $this->message('loaded'));
    }

    #[OA\Get(path: '/api/v1/ski-school/reference', operationId: 'skiSchoolReference', summary: 'Catalogue codes (disciplines, languages, levels, regimes, add-ons) and the settings of the school', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Reference data')])]
    public function reference(OverviewQueries $queries): JsonResponse
    {
        Gate::authorize('viewAny', SchoolSetting::class);

        return $this->successResponse($queries->reference(), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/ski-school/settings', operationId: 'skiSchoolSettingsUpdate', summary: 'Change the settings of the school (public portal slug and switch, jurisdiction, default retention)', security: self::SECURITY, tags: ['Ski school'], responses: [new OA\Response(response: 200, description: 'Settings')])]
    public function updateSettings(SaveSettingsRequest $request, SchoolSettingsService $settings, OverviewQueries $queries): JsonResponse
    {
        Gate::authorize('update', $settings->current());
        $settings->update($request->validated());

        return $this->successResponse($queries->reference()['settings'], $this->message('settings_saved'));
    }
}
