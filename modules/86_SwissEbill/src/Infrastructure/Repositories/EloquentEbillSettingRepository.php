<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Infrastructure\Repositories;

use App\Services\Tenancy\TenantContext;
use Modules\SwissEbill\Domain\Enums\EbillConnectionStatus;
use Modules\SwissEbill\Domain\Enums\EbillProtocol;
use Modules\SwissEbill\Domain\Enums\EbillProvider;
use Modules\SwissEbill\Domain\Repositories\EbillSettingRepositoryInterface;
use Modules\SwissEbill\Models\EbillSetting;

final class EloquentEbillSettingRepository implements EbillSettingRepositoryInterface
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function current(): ?EbillSetting
    {
        return EbillSetting::query()->first();
    }

    public function currentOrCreate(): EbillSetting
    {
        return $this->current() ?? EbillSetting::query()->create([
            'tenant_id' => $this->tenantContext->getTenantId(),
            'ebill_enabled' => false,
            'provider' => EbillProvider::SandboxSimulator->value,
            'channel_protocol' => EbillProtocol::RestJsonXml->value,
            'attach_courtesy_pdf' => true,
            'default_ebinterface_version' => 'SIX eBill XML',
            'connection_status' => EbillConnectionStatus::Untested->value,
        ]);
    }

    public function findByWebhookSlug(string $slug): ?EbillSetting
    {
        return EbillSetting::withoutGlobalScopes()->where('webhook_slug', $slug)->whereNull('deleted_at')->first();
    }

    public function save(EbillSetting $setting): EbillSetting
    {
        $setting->save();

        return $setting;
    }
}
