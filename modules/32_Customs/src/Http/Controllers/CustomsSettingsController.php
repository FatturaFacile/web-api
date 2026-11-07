<?php

declare(strict_types=1);

namespace Modules\Customs\Http\Controllers;

use Illuminate\Http\Request;
use Modules\Customs\Http\Requests\SaveCustomsSettingsRequest;
use Modules\Customs\Http\Resources\CustomsRecordResource;
use Modules\Customs\Models\CustomsSettings;
use OpenApi\Attributes as OA;

/**
 * The customs setup of the company: channel, declarant and ledger accounts.
 */
final class CustomsSettingsController extends CustomsController
{
    /**
     * Show the customs settings.
     */
    #[OA\Get(path: '/api/v1/customs/settings', operationId: 'showCustomsSettings', description: 'Return the customs settings of the company.', summary: 'Show customs settings', security: [['bearerAuth' => []]], tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Settings', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
    ])]
    public function show(Request $request): CustomsRecordResource
    {
        $this->authorize('viewAny', CustomsSettings::class);

        return new CustomsRecordResource(CustomsSettings::query()->firstOrNew([], ['channel' => config('customs.default_channel')]));
    }

    /**
     * Change the customs settings.
     */
    #[OA\Put(path: '/api/v1/customs/settings', operationId: 'updateCustomsSettings', description: 'Create or change the customs settings of the company. Choosing the e-dec channel does not open it: the installation must also be configured.', summary: 'Change customs settings', security: [['bearerAuth' => []]], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CustomsSettingsRequest')), tags: ['Customs'], responses: [
        new OA\Response(response: 200, description: 'Settings saved', content: new OA\JsonContent(ref: '#/components/schemas/CustomsRecordResponse')),
        new OA\Response(ref: '#/components/responses/CustomsUnauthenticated', response: 401),
        new OA\Response(ref: '#/components/responses/CustomsForbidden', response: 403),
        new OA\Response(ref: '#/components/responses/CustomsUnprocessable', response: 422),
    ])]
    public function update(SaveCustomsSettingsRequest $request): CustomsRecordResource
    {
        $this->authorize('create', CustomsSettings::class);

        $settings = CustomsSettings::query()->firstOrNew();
        $settings->fill($request->validated());
        $settings->save();
        $settings->wasRecentlyCreated = false;

        return new CustomsRecordResource($settings->refresh());
    }
}
