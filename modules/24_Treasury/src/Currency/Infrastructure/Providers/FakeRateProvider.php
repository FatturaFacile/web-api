<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Infrastructure\Providers;

use Modules\Treasury\Currency\Domain\Contracts\RateProvider;
use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;
use Modules\Treasury\Currency\Domain\ValueObjects\RateQuote;

/**
 * An in-memory provider for tests and local runs without network: it answers what it was given, or fails with the given
 * message. Tests bind it in the {@see RateProviderRegistry}, so no test ever calls the real feeds.
 */
final class FakeRateProvider implements RateProvider
{
    /**
     * @param  list<RateQuote>  $quotes
     */
    public function __construct(private readonly RateSource $source, private readonly array $quotes = [], private readonly ?string $failure = null) {}

    public function source(): RateSource
    {
        return $this->source;
    }

    public function fetch(): array
    {
        if ($this->failure !== null) {
            throw new RateProviderException($this->failure);
        }

        return $this->quotes;
    }
}
