<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use Modules\SwissEbill\Application\Dto\GatewayConnectionResult;
use Modules\SwissEbill\Domain\Enums\EbillConnectionStatus;
use Modules\SwissEbill\Domain\Exceptions\EbillException;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Infrastructure\Gateways\EbillGatewayFactory;

final class TestEbillConnectionHandler
{
    public function __construct(
        private readonly EbillSettingRepositoryInterface $settings,
        private readonly EbillGatewayFactory $gateways,
    ) {}

    public function handle(TestEbillConnectionCommand $command): GatewayConnectionResult
    {
        $setting = $this->settings->current();

        if ($setting === null) {
            throw new EbillException('not_configured', 'Swiss eBill is not configured for this company.', 422);
        }

        $result = $this->gateways->for($setting)->testConnection($setting);

        $setting->forceFill([
            'last_connection_tested_at' => now(),
            'connection_status' => $result->isConnected() ? EbillConnectionStatus::Connected : EbillConnectionStatus::Failed,
            'last_connection_latency_ms' => $result->latencyMs,
            'last_connection_message' => mb_substr($result->message, 0, 250),
            'updated_by' => $command->userId,
        ]);
        $this->settings->save($setting);

        return $result;
    }
}
