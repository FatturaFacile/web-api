<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Http\Requests;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Modules\Platform\Localization\Domain\Enums\SupportedLocale;
use Modules\Platform\Localization\Infrastructure\Policies\TranslationPolicy;

/**
 * Shared by the localization form requests: the active tenant, the acting user and the accepted locale codes.
 */
trait ResolvesLocalizationScope
{
    protected function tenantId(): ?string
    {
        return app(TenantContext::class)->getTenantId();
    }

    protected function mayWrite(): bool
    {
        $user = $this->user();
        $tenantId = $this->tenantId();

        return $user instanceof User && $tenantId !== null && app(TranslationPolicy::class)->update($user, $tenantId);
    }

    protected function mayRead(): bool
    {
        $user = $this->user();
        $tenantId = $this->tenantId();

        return $user instanceof User && $tenantId !== null && app(TranslationPolicy::class)->viewAny($user, $tenantId);
    }

    /**
     * The codes a client may send: the supported locales and the plain languages the dictionaries are stored under.
     *
     * @return list<string>
     */
    protected function localeCodes(): array
    {
        return [...SupportedLocale::values(), 'it', 'de', 'fr'];
    }
}
