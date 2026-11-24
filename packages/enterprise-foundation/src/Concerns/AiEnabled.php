<?php

declare(strict_types=1);

namespace Enterprise\Foundation\Concerns;

use Enterprise\AiGateway\Contracts\EnterpriseAiGatewayInterface;
use Enterprise\AiGateway\DTOs\AiRequest;
use Enterprise\AiGateway\DTOs\AiResponse;

trait AiEnabled
{
    public function askAi(string $taskType, array $payload): AiResponse
    {
        $gateway = app(EnterpriseAiGatewayInterface::class);

        return $gateway->predict(new AiRequest(
            taskType: $taskType,
            payload: $payload,
            tenantId: $this->tenant_id ?? (string) auth()->user()?->tenant_id,
            resourceType: static::class,
            resourceId: (string) $this->getKey()
        ));
    }
}
