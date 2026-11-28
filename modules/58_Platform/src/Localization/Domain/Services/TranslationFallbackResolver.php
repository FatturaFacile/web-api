<?php

declare(strict_types=1);

namespace Modules\Platform\Localization\Domain\Services;

use Modules\Platform\Localization\Domain\Contracts\TranslationLookup;
use Modules\Platform\Localization\Domain\ValueObjects\Locale;
use Psr\Log\LoggerInterface;

/**
 * Deterministic resolution of a key:
 *  1. tenant override for the exact locale      2. tenant override for the base language
 *  3. system text for the exact locale          4. system text for the base language
 *  5. system English                            6. the key itself (never empty), with a structured warning.
 */
final class TranslationFallbackResolver
{
    /** @var array<string, true> */
    private array $reported = [];

    public function __construct(private readonly TranslationLookup $lookup, private readonly LoggerInterface $logger) {}

    public function resolve(string $key, Locale $locale, ?string $tenantId): string
    {
        foreach ($this->candidates($locale, $tenantId) as [$scope, $candidate]) {
            $value = $this->lookup->find($scope, $candidate->value(), $key);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        $this->reportMissing($key, $locale, $tenantId);

        return $key;
    }

    /**
     * The lookups in priority order.
     *
     * @return list<array{0: ?string, 1: Locale}>
     */
    public function candidates(Locale $locale, ?string $tenantId): array
    {
        $base = $locale->base();
        $english = Locale::from('en');
        $chain = [];

        if ($tenantId !== null) {
            $chain[] = [$tenantId, $locale];

            if ($locale->isRegional()) {
                $chain[] = [$tenantId, $base];
            }
        }

        $chain[] = [null, $locale];

        if ($locale->isRegional()) {
            $chain[] = [null, $base];
        }

        $chain[] = [null, $english];

        // English requested explicitly would repeat the same lookups
        $seen = [];

        return array_values(array_filter($chain, static function (array $step) use (&$seen): bool {
            $id = ($step[0] ?? '').'|'.$step[1]->value();

            if (isset($seen[$id])) {
                return false;
            }

            return $seen[$id] = true;
        }));
    }

    private function reportMissing(string $key, Locale $locale, ?string $tenantId): void
    {
        $id = $tenantId.'|'.$locale->value().'|'.$key;

        if (isset($this->reported[$id])) {
            return;
        }

        $this->reported[$id] = true;

        $this->logger->warning('i18n.missing_translation_key', ['key' => $key, 'locale' => $locale->value(), 'tenant_id' => $tenantId]);
    }
}
