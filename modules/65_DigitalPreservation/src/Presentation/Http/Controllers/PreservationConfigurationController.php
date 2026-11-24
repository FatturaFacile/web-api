<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\DigitalPreservation\Application\Commands\UpdatePreservationConfigurationCommand;
use Modules\DigitalPreservation\Application\Handlers\UpdatePreservationConfigurationHandler;
use Modules\DigitalPreservation\Infrastructure\Connectors\PreservationConnectorManager;
use Modules\DigitalPreservation\Infrastructure\Services\PreservationConfigurationResolver;
use Modules\DigitalPreservation\Presentation\Http\Requests\UpdatePreservationConfigurationRequest;
use Modules\DigitalPreservation\Presentation\Http\Resources\PreservationConfigurationResource;
use OpenApi\Attributes as OA;

final class PreservationConfigurationController extends PreservationApiController
{
    #[OA\Get(path: '/api/v1/preservation/configuration', operationId: 'getPreservationConfiguration', description: 'Jurisdiction, preservation officer, TSA and conservator of the tenant. Credentials are never returned.', summary: 'Get the preservation configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], responses: [
        new OA\Response(response: 200, description: 'The configuration', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function show(PreservationConfigurationResolver $configurations): JsonResponse
    {
        return $this->successResponse((new PreservationConfigurationResource($configurations->current()))->resolve());
    }

    #[OA\Put(path: '/api/v1/preservation/configuration', operationId: 'updatePreservationConfiguration', description: 'Saves the configuration. Conservator and TSA credentials are encrypted and only replaced when sent.', summary: 'Update the preservation configuration', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], responses: [
        new OA\Response(response: 200, description: 'The configuration', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
        new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ApiErrorResponse')),
    ])]
    public function update(UpdatePreservationConfigurationRequest $request, UpdatePreservationConfigurationHandler $handler): JsonResponse
    {
        $data = $request->validated();
        $credentials = $data['credentials'] ?? null;
        $tsaCredentials = $data['tsa_credentials'] ?? null;
        unset($data['credentials'], $data['tsa_credentials']);

        $configuration = $handler->handle(new UpdatePreservationConfigurationCommand($data, $credentials, $tsaCredentials));

        return $this->successResponse((new PreservationConfigurationResource($configuration))->resolve(), 'Configuration saved');
    }

    #[OA\Post(path: '/api/v1/preservation/configuration/test-connection', operationId: 'testPreservationConnection', description: 'Checks the saved conservator credentials and endpoint.', summary: 'Test the conservator connection', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['Digital Preservation'], responses: [
        new OA\Response(response: 200, description: 'The result', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')),
    ])]
    public function testConnection(PreservationConfigurationResolver $configurations, PreservationConnectorManager $connectors): JsonResponse
    {
        $configuration = $configurations->current();
        $ok = $connectors->for($configuration->provider)->testConnection($configuration);

        return $this->successResponse(
            ['ok' => $ok, 'message' => $ok ? 'Connection successful.' : 'The conservator could not be reached or refused the credentials.', 'provider' => $configuration->provider->value],
        );
    }
}
