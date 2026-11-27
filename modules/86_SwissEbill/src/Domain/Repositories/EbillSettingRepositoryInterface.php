<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Domain\Repositories;

use Modules\SwissEbill\Models\EbillSetting;

interface EbillSettingRepositoryInterface
{
    /**
     * The settings of the active company, null when none were saved yet.
     */
    public function current(): ?EbillSetting;

    /**
     * The settings of the active company; created with defaults (and a fresh webhook slug) when missing.
     */
    public function currentOrCreate(): EbillSetting;

    /**
     * The company that owns a webhook slug, looked up without a tenant context (the webhook is public).
     */
    public function findByWebhookSlug(string $slug): ?EbillSetting;

    public function save(EbillSetting $setting): EbillSetting;
}
