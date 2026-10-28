<?php

declare(strict_types=1);

namespace Enterprise\EventBus\Providers;

use Enterprise\EventBus\Contracts\EventBusInterface;
use Enterprise\EventBus\Services\EventDispatcher;
use Enterprise\EventBus\Services\TransactionalInboxService;
use Enterprise\EventBus\Services\TransactionalOutboxService;
use Illuminate\Support\ServiceProvider;

final class EventBusServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TransactionalOutboxService::class);
        $this->app->singleton(TransactionalInboxService::class);
        $this->app->singleton(EventBusInterface::class, EventDispatcher::class);
        $this->app->singleton(EventDispatcher::class);
    }
}
