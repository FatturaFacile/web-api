<?php

declare(strict_types=1);

namespace Modules\DairyCheeseManufacturing\Application\Commands;

final readonly class CompleteVatBatchCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public string $batchId, public array $data) {}
}
