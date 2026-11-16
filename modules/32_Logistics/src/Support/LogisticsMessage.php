<?php

declare(strict_types=1);

namespace Modules\Logistics\Support;

use App\Models\Translation;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;

/**
 * Business-error messages resolved from the `translations` table (keys LOGISTICS.ERRORS.*) in the request locale,
 * so API errors reach the client already translated. Tenant overrides win over the global text; `{name}`
 * placeholders are replaced from $params. Falls back to English, then to the key itself.
 */
final class LogisticsMessage
{
    /**
     * @param  array<string, scalar|null>  $params
     */
    public static function get(string $key, array $params = []): string
    {
        $text = self::lookup($key, app()->getLocale())
            ?? self::lookup($key, 'en')
            ?? $key;

        $replace = [];
        foreach ($params as $name => $value) {
            $replace['{'.$name.'}'] = (string) $value;
        }

        return strtr($text, $replace);
    }

    private static function lookup(string $key, string $locale): ?string
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        if ($tenantId !== null) {
            $override = Translation::query()->where('tenant_id', $tenantId)->where('locale', $locale)->where('key', $key)->value('value');
            if (is_string($override) && $override !== '') {
                return $override;
            }
        }

        $global = Cache::rememberForever("translations_global_{$locale}", fn (): array => Translation::query()
            ->whereNull('tenant_id')->where('locale', $locale)->pluck('value', 'key')->map(fn ($v): string => (string) $v)->all());

        return is_array($global) && isset($global[$key]) && $global[$key] !== '' ? $global[$key] : null;
    }
}
