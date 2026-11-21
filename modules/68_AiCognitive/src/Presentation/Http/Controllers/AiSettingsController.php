<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Presentation\Http\Controllers;

use Enterprise\AiGateway\Contracts\TenantAiConsentInterface;
use Illuminate\Http\JsonResponse;
use Modules\AiCognitive\Application\Commands\ConfigureAiProviderCommand;
use Modules\AiCognitive\Application\Commands\ConfigureAutomationRuleCommand;
use Modules\AiCognitive\Application\Handlers\ConfigureAiProviderHandler;
use Modules\AiCognitive\Application\Handlers\ConfigureAutomationRuleHandler;
use Modules\AiCognitive\Domain\Enums\AiProviderType;
use Modules\AiCognitive\Domain\Enums\DataClassification;
use Modules\AiCognitive\Infrastructure\Models\AiAutomationRule;
use Modules\AiCognitive\Infrastructure\Models\AiProviderConfig;
use Modules\AiCognitive\Infrastructure\Services\AutomationPolicy;
use Modules\AiCognitive\Presentation\Http\Requests\ConfigureAiProviderRequest;
use Modules\AiCognitive\Presentation\Http\Requests\ConfigureAutomationRuleRequest;
use Modules\AiCognitive\Presentation\Http\Resources\AiProviderConfigResource;
use OpenApi\Attributes as OA;

final class AiSettingsController extends AiApiController
{
    #[OA\Get(path: '/api/v1/ai/settings', operationId: 'showAiSettings', description: 'The provider setup of the tenant (secrets are never returned), the automation rules of every workflow with their effective policy, the supported providers with their defaults and whether the tenant allowed external AI providers.', summary: 'AI governance settings', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], responses: [new OA\Response(response: 200, description: 'The settings', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function show(TenantAiConsentInterface $consent, AutomationPolicy $policy): JsonResponse
    {
        $tenantId = $this->tenantId();

        $rules = [];

        foreach (ConfigureAutomationRuleRequest::workflows() as $workflow) {
            $rule = $policy->rule($tenantId, $workflow);
            $rules[] = [
                'workflow' => $workflow,
                'is_enabled' => $rule['enabled'],
                'auto_approve_enabled' => $rule['auto_approve'],
                'auto_approve_min_confidence' => $rule['min_confidence'],
                'max_auto_amount' => $rule['max_amount'],
                'data_classification' => $rule['classification']->value,
                'preferred_provider' => $rule['preferred_provider'],
                'configured' => AiAutomationRule::query()->where('tenant_id', $tenantId)->where('workflow', $workflow)->exists(),
            ];
        }

        return $this->successResponse([
            'external_providers_allowed' => $consent->allowsExternalProviders($tenantId),
            'providers' => AiProviderConfigResource::collection(AiProviderConfig::query()->where('tenant_id', $tenantId)->orderBy('provider')->get())->resolve(),
            'rules' => $rules,
            'supported_providers' => array_map(static fn (AiProviderType $provider): array => ['provider' => $provider->value, 'is_local' => $provider->isLocal()] + (array) config('ai_cognitive.defaults.'.$provider->value, []), AiProviderType::cases()),
            'data_classifications' => array_map(static fn (DataClassification $classification): string => $classification->value, DataClassification::cases()),
        ]);
    }

    #[OA\Put(path: '/api/v1/ai/settings/providers/{provider}', operationId: 'configureAiProvider', description: 'Configures one provider of the tenant: local Ollama, or a hosted provider on the tenant API key (stored encrypted). Sets the PII masking, the highest data classification the provider may receive and the token and rate limits.', summary: 'Configure an AI provider', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], parameters: [new OA\Parameter(name: 'provider', in: 'path', required: true, schema: new OA\Schema(type: 'string', enum: ['ollama', 'openai', 'anthropic', 'gemini', 'azure_openai']))], responses: [new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse'))])]
    public function updateProvider(ConfigureAiProviderRequest $request, ConfigureAiProviderHandler $handler): JsonResponse
    {
        $data = $request->validated();

        return $this->guarded(fn (): JsonResponse => $this->successResponse(new AiProviderConfigResource($handler->handle(new ConfigureAiProviderCommand(
            $this->tenantId(),
            $this->userId(),
            $request->provider(),
            (bool) $data['is_enabled'],
            (bool) ($data['is_default'] ?? false),
            $data['label'] ?? null,
            $data['endpoint'] ?? null,
            $data['model'] ?? null,
            $data['light_model'] ?? null,
            $data['embedding_model'] ?? null,
            $data['api_key'] ?? null,
            array_filter((array) ($data['extra'] ?? []), static fn (mixed $value): bool => $value !== null && $value !== ''),
            DataClassification::from($data['max_data_classification']),
            (bool) $data['pii_masking_enabled'],
            isset($data['latency_sla_ms']) ? (int) $data['latency_sla_ms'] : null,
            isset($data['monthly_token_budget']) ? (int) $data['monthly_token_budget'] : null,
            isset($data['rate_limit_per_minute']) ? (int) $data['rate_limit_per_minute'] : null,
        ))), 'AI provider saved'));
    }

    #[OA\Put(path: '/api/v1/ai/settings/rules/{workflow}', operationId: 'configureAiAutomationRule', description: 'Sets the zero-click policy of a workflow: auto-execute when the confidence is at or above the threshold and the amount below the limit, otherwise the item waits in the review workbench.', summary: 'Configure an automation rule', security: [['bearerAuth' => [], 'tenantHeader' => []]], tags: ['AI Cognitive'], parameters: [new OA\Parameter(name: 'workflow', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], responses: [new OA\Response(response: 200, description: 'Saved', content: new OA\JsonContent(ref: '#/components/schemas/ApiSuccessResponse')), new OA\Response(response: 404, description: 'Unknown workflow')])]
    public function updateRule(ConfigureAutomationRuleRequest $request, string $workflow, ConfigureAutomationRuleHandler $handler): JsonResponse
    {
        if (! in_array($workflow, ConfigureAutomationRuleRequest::workflows(), true)) {
            return $this->problemDetailsResponse('Not Found', "Unknown workflow [{$workflow}].", 404);
        }

        $data = $request->validated();

        $rule = $handler->handle(new ConfigureAutomationRuleCommand(
            $this->tenantId(),
            $this->userId(),
            $workflow,
            (bool) $data['is_enabled'],
            (bool) $data['auto_approve_enabled'],
            (float) $data['auto_approve_min_confidence'],
            isset($data['max_auto_amount']) ? (float) $data['max_auto_amount'] : null,
            DataClassification::from($data['data_classification']),
            $data['preferred_provider'] ?? null,
        ));

        return $this->successResponse([
            'workflow' => $rule->workflow,
            'is_enabled' => $rule->is_enabled,
            'auto_approve_enabled' => $rule->auto_approve_enabled,
            'auto_approve_min_confidence' => (float) $rule->auto_approve_min_confidence,
            'max_auto_amount' => $rule->max_auto_amount === null ? null : (float) $rule->max_auto_amount,
            'data_classification' => $rule->data_classification->value,
            'preferred_provider' => $rule->preferred_provider,
        ], 'Automation rule saved');
    }
}
