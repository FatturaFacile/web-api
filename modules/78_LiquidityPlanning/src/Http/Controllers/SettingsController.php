<?php

declare(strict_types=1);

namespace Modules\LiquidityPlanning\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\LiquidityPlanning\Http\Controllers\Concerns\ResolvesLiquidityContext;
use Modules\LiquidityPlanning\Http\Requests\SettingsRequest;
use Modules\LiquidityPlanning\Http\Resources\SettingsResource;
use Modules\LiquidityPlanning\Models\LiquiditySettings;
use Modules\LiquidityPlanning\Services\LiquidityTexts;
use OpenApi\Attributes as OA;

/**
 * The liquidity planning settings: jurisdiction, share capital, thresholds and alert notifications.
 */
final class SettingsController extends Controller
{
    use ApiResponse;
    use ResolvesLiquidityContext;

    #[OA\Get(path: '/api/v1/liquidity-planning/settings', operationId: 'getLiquidityPlanningSettings', summary: 'Liquidity planning settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The settings')])]
    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(new SettingsResource(LiquiditySettings::current((string) $this->tenant()->country)), LiquidityTexts::get('lpApi.message.settings', LiquidityTexts::locale()));
    }

    #[OA\Put(path: '/api/v1/liquidity-planning/settings', operationId: 'updateLiquidityPlanningSettings', summary: 'Change the liquidity planning settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Liquidity Planning'], responses: [new OA\Response(response: 200, description: 'The settings'), new OA\Response(response: 422, description: 'Not valid')])]
    public function update(SettingsRequest $request): JsonResponse
    {
        $settings = LiquiditySettings::query()->first() ?? new LiquiditySettings;
        $settings->fill($request->validated());
        $settings->notify_emails = array_values(array_unique((array) $request->validated('notify_emails', [])));
        $settings->save();

        return $this->successResponse(new SettingsResource($settings), LiquidityTexts::get('lpApi.message.settings_saved', LiquidityTexts::locale()));
    }
}
