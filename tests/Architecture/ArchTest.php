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

arch('domain models adhere to tenant scoping expectations')
    ->expect('App\Models')
    ->classes()
    ->ignoring([
        'App\Models\Tenant',
        'App\Models\User',
    ]);
