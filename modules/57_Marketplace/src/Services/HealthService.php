<?php

declare(strict_types=1);

namespace Modules\Marketplace\Services;

use Illuminate\Validation\ValidationException;
use Modules\Marketplace\Models\MarketplaceHealthCheck;
use Modules\Marketplace\Models\MarketplaceInstallation;
use Modules\Marketplace\Support\ConnectorRegistry;
use Modules\Marketplace\Support\HealthCheckResult;
use Throwable;

/**
 * Health checks of the installations. A check needs a configuration (there is nothing to check before it); the result is
 * stored in the history of the installation and summarised on the installation itself. A connector that throws instead of
 * reporting is recorded as unhealthy, and the message is cleaned of the credentials of the installation.
 */
final readonly class HealthService
{
    /**
     * Inject the collaborators.
     */
    public function __construct(private ConnectorRegistry $registry, private ConnectorContextFactory $contexts) {}

    /**
     * Runs a check now.
     *
     * @throws ValidationException when the installation is not configured yet
     */
    public function check(MarketplaceInstallation $installation, string $reason = 'manual'): MarketplaceHealthCheck
    {
        if (! $installation->isConfigured()) {
            throw ValidationException::withMessages(['status' => [__('marketplace_errors.installation.not_configured')]]);
        }

        $context = $this->contexts->for($installation);
        $connector = $this->registry->find($installation->connector_key);

        try {
            $result = $connector === null
                ? new HealthCheckResult(HealthCheckResult::Unhealthy, __('marketplace_errors.installation.unknown_connector'))
                : $connector->checkHealth($context);
        } catch (Throwable $exception) {
            report($exception);
            $result = new HealthCheckResult(HealthCheckResult::Unhealthy, __('marketplace_errors.health.failed'));
        }

        $message = $result->message === null ? null : mb_substr($context->redact($result->message), 0, 255);
        $check = MarketplaceHealthCheck::create([
            'tenant_id' => $installation->tenant_id,
            'installation_id' => $installation->id,
            'status' => $result->status,
            'latency_ms' => $result->latencyMs,
            'message' => $message,
            'checked_at' => now(),
        ]);

        $installation->update(['last_health_status' => $result->status, 'last_health_checked_at' => $check->checked_at, 'last_health_message' => $message]);
        $this->prune($installation);

        return $check;
    }

    /**
     * Checks every enabled installation of the active company; one that fails to check does not stop the others.
     *
     * @return array{checked: int, unhealthy: int}
     */
    public function checkAllEnabled(): array
    {
        $outcome = ['checked' => 0, 'unhealthy' => 0];

        foreach (MarketplaceInstallation::query()->where('status', MarketplaceInstallation::Enabled)->get() as $installation) {
            try {
                $check = $this->check($installation, 'schedule');
                $outcome['checked']++;
                $outcome['unhealthy'] += $check->status === HealthCheckResult::Unhealthy ? 1 : 0;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $outcome;
    }

    private function prune(MarketplaceInstallation $installation): void
    {
        $keep = max(1, (int) config('marketplace.health_checks_keep', 50));
        $surplus = MarketplaceHealthCheck::query()->where('installation_id', $installation->id)->orderByDesc('checked_at')->orderByDesc('id')->skip($keep)->take(1000)->pluck('id');

        if ($surplus->isNotEmpty()) {
            MarketplaceHealthCheck::query()->whereIn('id', $surplus->all())->forceDelete();
        }
    }
}
