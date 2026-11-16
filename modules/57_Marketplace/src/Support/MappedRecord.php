<?php

declare(strict_types=1);

namespace Modules\Marketplace\Support;

/**
 * A pulled record in the normalised shape of the application: the kind of entity, the id it has in the external system
 * and the normalised payload (decimal amounts as strings, dates as ISO 8601, currencies in upper case).
 */
final readonly class MappedRecord
{
    public const string Order = 'order';

    public const string Product = 'product';

    public const string Customer = 'customer';

    public const string Payment = 'payment';

    public const string Payout = 'payout';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public string $entityType, public string $externalId, public array $payload) {}
}
