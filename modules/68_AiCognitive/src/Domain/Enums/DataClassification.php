<?php

declare(strict_types=1);

namespace Modules\AiCognitive\Domain\Enums;

/**
 * How sensitive the data of a request is, from the least to the most. A provider accepts data up to the classification the
 * tenant configured for it.
 */
enum DataClassification: string
{
    case Public = 'public';
    case Internal = 'internal';
    case ConfidentialPii = 'confidential_pii';

    public function rank(): int
    {
        return match ($this) {
            self::Public => 0,
            self::Internal => 1,
            self::ConfidentialPii => 2,
        };
    }

    public function atMost(self $limit): bool
    {
        return $this->rank() <= $limit->rank();
    }
}
