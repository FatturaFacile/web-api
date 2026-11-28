<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Infrastructure\Persistence;

use App\Support\RegionalTranslations;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;

/**
 * Builds the complete dictionary of a locale for a tenant by layering the fallback chain from the weakest to the
 * strongest source: system English, system language, regional spelling, system exact locale, tenant language and tenant
 * exact locale. Every key of the system dictionary is therefore present, and a tenant override always wins.
 */
final class DictionaryBuilder
{
    public function __construct(private readonly DatabaseTranslationRepository $repository) {}

    /**
     * @return array<string, string>
     */
    public function build(?string $tenantId, Locale $locale): array
    {
        $base = $locale->base();
        $entries = $this->repository->dictionary(null, 'en');

        if ($base->value() !== 'en') {
            $entries = array_replace($entries, $this->repository->dictionary(null, $base->value()));
        }

        if ($locale->isRegional()) {
            $entries = array_replace(RegionalTranslations::derive($locale->value(), $entries), $this->repository->dictionary(null, $locale->value()));
        }

        if ($tenantId !== null) {
            if ($locale->isRegional()) {
                $entries = array_replace($entries, $this->repository->dictionary($tenantId, $base->value()));
            }

            $entries = array_replace($entries, $this->repository->dictionary($tenantId, $locale->value()));
        }

        return $entries;
    }
}
