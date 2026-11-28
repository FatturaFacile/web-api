<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Services;

use Illuminate\Support\Facades\DB;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\Services\DocumentLocaleResolver;
use Modules\Platform\Localization\Infrastructure\Persistence\TenantLocaleSettingsRepository;

/**
 * Resolves the locale a new document is frozen with: the document language of its contact, else the default locale of
 * the issuing company.
 */
final class DocumentLocaleFreezer
{
    public function __construct(
        private readonly DocumentLocaleResolver $resolver,
        private readonly TenantLocaleSettingsRepository $settings,
    ) {}

    public function resolve(?string $tenantId, ?string $contactId): string
    {
        $contactLocale = $contactId === null ? null : DB::table('contacts')->where('id', $contactId)->value('document_language');
        $company = $tenantId === null ? null : $this->settings->get($tenantId)->defaultLocale;

        return $this->resolver->resolve(
            null,
            is_string($contactLocale) ? $contactLocale : null,
            $company ?? SupportedLocale::En,
            $tenantId === null ? null : $this->settings->country($tenantId),
        )->value;
    }
}
