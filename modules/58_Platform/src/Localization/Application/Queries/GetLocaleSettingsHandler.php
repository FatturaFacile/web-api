<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Application\Queries;

use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Domain\ValueObjects\LocaleSettings;
use Modules\Platform\Localization\Infrastructure\Persistence\TenantLocaleSettingsRepository;

/**
 * The settings of a tenant plus the catalogue of the locales it can pick from.
 */
final class GetLocaleSettingsHandler
{
    public function __construct(private readonly TenantLocaleSettingsRepository $settings) {}

    /**
     * @return array{settings: LocaleSettings, available_locales: list<array{code: string, language: string, region: string|null, currency: string, ng_zorro_locale: string}>}
     */
    public function __invoke(GetLocaleSettingsQuery $query): array
    {
        return [
            'settings' => $this->settings->get($query->tenantId),
            'available_locales' => self::catalogue(),
        ];
    }

    /**
     * @return list<array{code: string, language: string, region: string|null, currency: string, ng_zorro_locale: string}>
     */
    public static function catalogue(): array
    {
        return array_map(static fn (SupportedLocale $locale): array => [
            'code' => $locale->value,
            'language' => $locale->language(),
            'region' => $locale->region(),
            'currency' => $locale->defaultCurrency(),
            'ng_zorro_locale' => $locale->ngZorroLocale(),
        ], SupportedLocale::cases());
    }
}
