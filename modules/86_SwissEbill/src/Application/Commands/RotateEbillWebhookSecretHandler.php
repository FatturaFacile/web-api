<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

use Illuminate\Support\Str;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;

/**
 * Issues a new webhook secret. The plain value is returned once, to be pasted into the SIX Paynet / PostFinance portal.
 */
final class RotateEbillWebhookSecretHandler
{
    public function __construct(private readonly EbillSettingRepositoryInterface $settings) {}

    public function handle(?string $userId = null): string
    {
        $setting = $this->settings->currentOrCreate();
        $secret = Str::random(48);
        $setting->forceFill(['webhook_secret' => $secret, 'updated_by' => $userId]);
        $this->settings->save($setting);

        return $secret;
    }
}
