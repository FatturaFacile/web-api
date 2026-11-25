<?php

declare(strict_types=1);

namespace Modules\IndustrialLaundry\Application\Commands;

/**
 * Certifies a wash batch against UNI EN 14065 RABC from what the wash line measured.
 */
final readonly class CertifyWashBatchCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public string $washBatchId, public ?string $userId, public array $data) {}
}
