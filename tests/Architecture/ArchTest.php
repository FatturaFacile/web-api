<?php

declare(strict_types=1);

namespace Tests\Architecture;

arch('application and modules do not contain debugging functions')
    ->expect(['App', 'Modules'])
    ->not->toUse(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die', 'exit']);

arch('strict types are declared in all app and modules files')
    ->expect(['App', 'Modules'])
    ->toUseStrictTypes();

arch('controllers do not execute direct database queries or raw queries')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB'])
    ->ignoring([
        'App\Http\Controllers\HealthController',
    ]);

// The tenant scope itself is enforced model by model in tests/Feature/Architecture (TenantIsolationArchTest).
arch('domain models are Eloquent models')
    ->expect('App\Models')
    ->classes()
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->ignoring([
        'App\Models\Scopes',
        'App\Models\Tenant',
        'App\Models\User',
    ]);
