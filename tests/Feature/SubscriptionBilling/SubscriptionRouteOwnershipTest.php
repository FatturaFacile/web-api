<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

test('the contract lifecycle of the subscription billing module lives only under /api/v1/sub/contracts', function (string $action): void {
    $legacy = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => $route->uri() === "api/v1/subscriptions/{id}/{$action}");
    expect($legacy->map(fn ($route): string => (string) $route->getActionName())->filter(fn (string $name): bool => str_starts_with($name, 'Modules\\SubscriptionBilling'))->all())->toBe([]);

    $contract = Route::getRoutes()->match(Request::create("/api/v1/sub/contracts/0190a5f2-0000-7000-8000-000000000001/{$action}", 'POST'));
    expect($contract->getActionName())->toStartWith('Modules\\SubscriptionBilling\\Http\\Controllers\\SubscriptionController@');
})->with(['activate', 'suspend', 'reactivate', 'upgrade', 'downgrade']);

test('the legacy recurring-billing subscriptions keep their own /api/v1/subscriptions/{id} actions', function (): void {
    $route = Route::getRoutes()->match(Request::create('/api/v1/subscriptions/0190a5f2-0000-7000-8000-000000000001/pause', 'POST'));

    expect($route->getActionName())->toStartWith('App\\Http\\Controllers\\Api\\V1\\SubscriptionController@');
});
