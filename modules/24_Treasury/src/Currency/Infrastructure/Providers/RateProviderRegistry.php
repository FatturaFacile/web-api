<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Providers;

use InvalidArgumentException;
use Modules\Treasury\Currency\Domain\Contracts\RateProvider;
use Modules\Treasury\Currency\Domain\Enums\RateSource;

/**
 * The providers by source. The container builds the real ones; a test replaces any of them with a {@see FakeRateProvider} through
 * {@see self::swap()} (and the registry is bound as a singleton).
 */
final class RateProviderRegistry
{
    /** @var array<string, RateProvider> */
    private array $providers = [];

    /**
     * @param  list<RateProvider>  $providers
     */
    public function __construct(array $providers = [])
    {
        foreach ($providers as $provider) {
            $this->swap($provider);
        }
    }

    public function swap(RateProvider $provider): void
    {
        $this->providers[$provider->source()->value] = $provider;
    }

    public function get(RateSource $source): RateProvider
    {
        return $this->providers[$source->value] ?? throw new InvalidArgumentException("No rate provider for [{$source->value}].");
    }

    public function has(RateSource $source): bool
    {
        return isset($this->providers[$source->value]);
    }

    /**
     * @return list<RateSource>
     */
    public function sources(): array
    {
        return array_values(array_map(static fn (RateProvider $provider): RateSource => $provider->source(), $this->providers));
    }
}
