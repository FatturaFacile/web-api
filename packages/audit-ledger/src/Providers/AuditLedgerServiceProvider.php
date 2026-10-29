<?php

declare(strict_types=1);

namespace Enterprise\AuditLedger\Providers;

use Enterprise\AuditLedger\Contracts\AuditLedgerInterface;
use Enterprise\AuditLedger\Services\AuditChainVerificationService;
use Enterprise\AuditLedger\Services\AuditLedgerService;
use Illuminate\Support\ServiceProvider;

final class AuditLedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLedgerInterface::class, AuditLedgerService::class);
        $this->app->singleton(AuditLedgerService::class);
        $this->app->singleton(AuditChainVerificationService::class);
    }
}
