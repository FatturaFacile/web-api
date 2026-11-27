<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Modules\SwissEbill\Application\Commands\RotateEbillWebhookSecretHandler;
use Modules\SwissEbill\Application\Commands\SaveEbillSettingsCommand;
use Modules\SwissEbill\Application\Commands\SaveEbillSettingsHandler;
use Modules\SwissEbill\Application\Commands\TestEbillConnectionCommand;
use Modules\SwissEbill\Application\Commands\TestEbillConnectionHandler;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Http\Controllers\Concerns\InteractsWithEbill;
use Modules\SwissEbill\Http\Requests\NoInputRequest;
use Modules\SwissEbill\Http\Requests\SaveEbillSettingsRequest;
use Modules\SwissEbill\Http\Resources\EbillSettingResource;
use Modules\SwissEbill\Models\EbillSetting;
use OpenApi\Attributes as OA;

/**
 * Company settings of the Swiss eBill channel: biller id, provider, protocol, credentials, webhook and delivery options.
 */
final class EbillSettingsController extends Controller
{
    use ApiResponse;
    use InteractsWithEbill;

    private const array SECURITY = [['bearerAuth' => [], 'tenantHeader' => []]];

    #[OA\Get(path: '/api/v1/swiss-ebill/settings', operationId: 'swissEbillShowSettings', summary: 'Show the Swiss eBill settings of the company (secrets are never returned)', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 200, description: 'Settings')])]
    public function show(EbillSettingRepositoryInterface $settings): JsonResponse
    {
        Gate::authorize('viewAny', EbillSetting::class);

        return $this->successResponse(new EbillSettingResource($settings->currentOrCreate()), $this->message('loaded'));
    }

    #[OA\Put(path: '/api/v1/swiss-ebill/settings', operationId: 'swissEbillSaveSettings', summary: 'Save the Swiss eBill settings of the company', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 200, description: 'Saved settings')])]
    public function update(SaveEbillSettingsRequest $request, SaveEbillSettingsHandler $handler): JsonResponse
    {
        Gate::authorize('create', EbillSetting::class);

        $setting = $handler->handle(new SaveEbillSettingsCommand($request->validated(), $this->userId()));

        return $this->successResponse(new EbillSettingResource($setting), $this->message('saved'));
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/settings/test-connection', operationId: 'swissEbillTestConnection', summary: 'Verify the credentials against the configured gateway', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 200, description: 'Reachability, authentication and latency')])]
    public function testConnection(NoInputRequest $request, TestEbillConnectionHandler $handler, EbillSettingRepositoryInterface $settings): JsonResponse
    {
        Gate::authorize('create', EbillSetting::class);

        $result = $handler->handle(new TestEbillConnectionCommand($this->userId()));

        return $this->successResponse(['result' => $result->toArray(), 'settings' => new EbillSettingResource($settings->currentOrCreate())], $this->message($result->isConnected() ? 'connection_ok' : 'connection_failed'));
    }

    #[OA\Post(path: '/api/v1/swiss-ebill/settings/rotate-webhook-secret', operationId: 'swissEbillRotateWebhookSecret', summary: 'Issue a new webhook secret (returned once)', security: self::SECURITY, tags: ['Swiss eBill'], responses: [new OA\Response(response: 200, description: 'Settings with the new secret')])]
    public function rotateWebhookSecret(NoInputRequest $request, RotateEbillWebhookSecretHandler $handler, EbillSettingRepositoryInterface $settings): JsonResponse
    {
        Gate::authorize('create', EbillSetting::class);

        $secret = $handler->handle($this->userId());

        return $this->successResponse(new EbillSettingResource($settings->currentOrCreate(), $secret), $this->message('secret_rotated'));
    }
}
