<?php

declare(strict_types=1);

namespace Enterprise\MultiTenant\Providers;

use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Queue\TenantQueueContext;
use Enterprise\MultiTenant\Scopes\TenantScope;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

final class MultiTenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(TenantContext::class)) {
            $this->app->singleton(TenantContext::class, fn (): TenantContext => new TenantContext);
        }

        $this->app->singleton(TenantScope::class);
        $this->app->singleton(TenantQueueContext::class);
    }

    public function boot(): void
    {
        Queue::createPayloadUsing(fn (): array => $this->app->make(TenantQueueContext::class)->payload());

        Event::listen(JobProcessing::class, fn (JobProcessing $event) => $this->app->make(TenantQueueContext::class)->enter($event->job->payload()));

        $leave = fn () => $this->app->make(TenantQueueContext::class)->leave();

        Event::listen(JobProcessed::class, $leave);
        Event::listen(JobExceptionOccurred::class, $leave);
    }
}
