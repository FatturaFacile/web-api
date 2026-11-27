<?php

declare(strict_types=1);

namespace Modules\SwissEbill\Application\Commands;

/**
 * Saves the Swiss eBill configuration of the active company. A secret left out (null or empty) keeps the stored one.
 */
final readonly class SaveEbillSettingsCommand
{
    /**
     * @param  array<string, mixed>  $values  validated fields of SaveEbillSettingsRequest
     */
    public function __construct(public array $values, public ?string $userId = null) {}
}
