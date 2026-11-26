<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Domain\Contracts;

use Modules\Treasury\Currency\Domain\Enums\RateSource;
use Modules\Treasury\Currency\Domain\Exceptions\RateProviderException;
use Modules\Treasury\Currency\Domain\ValueObjects\RateQuote;

/**
 * A source of official rates (ECB, SNB, ESTV). A provider that cannot deliver a valid feed throws; it never answers with
 * built-in rates.
 */
interface RateProvider
{
    public function source(): RateSource;

    /**
     * @return list<RateQuote>
     *
     * @throws RateProviderException
     */
    public function fetch(): array;
}
