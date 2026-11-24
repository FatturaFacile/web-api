<?php

declare(strict_types=1);

namespace Modules\DigitalPreservation\Application\Commands;

/**
 * Save the preservation configuration of the tenant. Credentials are only replaced when given.
 */
final readonly class UpdatePreservationConfigurationCommand
{
    /**
     * @param  array<string, mixed>  $attributes  validated configuration fields
     * @param  array<string, mixed>|null  $credentials  null keeps the stored value
     * @param  array<string, mixed>|null  $tsaCredentials  null keeps the stored value
     */
    public function __construct(public array $attributes, public ?array $credentials = null, public ?array $tsaCredentials = null) {}
}
