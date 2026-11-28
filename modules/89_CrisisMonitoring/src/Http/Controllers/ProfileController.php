<?php

declare(strict_types=1);

namespace Modules\CrisisMonitoring\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\CrisisMonitoring\Application\Services\CrisisTexts;
use Modules\CrisisMonitoring\Http\Controllers\Concerns\ResolvesCrisisContext;
use Modules\CrisisMonitoring\Http\Requests\ProfileRequest;
use Modules\CrisisMonitoring\Http\Resources\ProfileResource;
use Modules\CrisisMonitoring\Models\CrisisProfile;
use Modules\CrisisMonitoring\Support\AtecoSection;
use OpenApi\Attributes as OA;

/**
 * The crisis profile of the company.
 */
final class ProfileController extends Controller
{
    use ApiResponse;
    use ResolvesCrisisContext;

    #[OA\Get(path: '/api/v1/crisis-monitoring/profile', operationId: 'showCrisisMonitoringProfile', summary: 'Crisis profile of the company', description: 'Without a stored profile, the defaults of the country of the company.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The profile')])]
    public function show(): JsonResponse
    {
        return $this->successResponse(new ProfileResource(CrisisProfile::current((string) $this->tenant()->country)), CrisisTexts::get('crisisApi.message.profile', CrisisTexts::locale()));
    }

    #[OA\Put(path: '/api/v1/crisis-monitoring/profile', operationId: 'updateCrisisMonitoringProfile', summary: 'Save the crisis profile', description: 'Only the fields sent change. The ATECO section is derived from the ATECO code when it is not sent.', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Crisis Monitoring'], responses: [new OA\Response(response: 200, description: 'The profile')])]
    public function update(ProfileRequest $request): JsonResponse
    {
        $attributes = $request->validated();

        if (array_key_exists('employees_count', $attributes)) {
            $attributes['employees_count'] = (int) $attributes['employees_count'];
        }

        if (array_key_exists('ateco_code', $attributes) && ! array_key_exists('ateco_section', $attributes)) {
            $attributes['ateco_section'] = AtecoSection::fromCode($attributes['ateco_code']);
        }

        $profile = CrisisProfile::query()->first() ?? new CrisisProfile(['jurisdiction' => $this->tenant()->country === 'CH' ? 'CH' : 'IT']);
        $profile->fill($attributes)->save();

        return $this->successResponse(new ProfileResource($profile->refresh()), CrisisTexts::get('crisisApi.message.profile_saved', CrisisTexts::locale()));
    }
}
