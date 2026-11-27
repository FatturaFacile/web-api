<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Queries;

/**
 * The transmission log, filtered and paged.
 */
final readonly class ListEbillTransmissionsQuery
{
    /**
     * @param  array{ebill_status?: ?string, from?: ?string, to?: ?string, customer_id?: ?string, invoice_id?: ?string, channel_type?: ?string, search?: ?string}  $filters
     */
    public function __construct(public array $filters = [], public int $perPage = 25) {}
}
