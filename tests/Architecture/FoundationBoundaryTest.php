<?php

declare(strict_types=1);

arch('foundation packages enforce strict typing across all classes')
    ->expect([
        'Enterprise\Foundation',
        'Enterprise\MultiTenant',
        'Enterprise\AuditLedger',
        'Enterprise\AiGateway',
        'Enterprise\EventBus',
        'Enterprise\FeatureFlags',
        'Enterprise\OpenApi',
    ])
    ->toUseStrictTypes();

arch('all foundation contracts are interfaces')
    ->expect([
        'Enterprise\AuditLedger\Contracts',
        'Enterprise\AiGateway\Contracts',
        'Enterprise\EventBus\Contracts',
        'Enterprise\FeatureFlags\Contracts',
    ])
    ->toBeInterfaces();

arch('base enterprise model is abstract')
    ->expect('Enterprise\Foundation\Models\BaseEnterpriseModel')
    ->toBeAbstract();

arch('ai gateway dtos are final readonly classes')
    ->expect([
        'Enterprise\AiGateway\DTOs\AiRequest',
        'Enterprise\AiGateway\DTOs\AiResponse',
        'Enterprise\AiGateway\DTOs\AiPayload',
    ])
    ->toBeClasses()
    ->toBeFinal()
    ->toBeReadonly();

arch('foundation packages do not depend on domain modules')
    ->expect([
        'Enterprise\Foundation',
        'Enterprise\MultiTenant',
        'Enterprise\AuditLedger',
        'Enterprise\AiGateway',
        'Enterprise\EventBus',
        'Enterprise\FeatureFlags',
        'Enterprise\OpenApi',
    ])
    ->not->toUse('Modules');
