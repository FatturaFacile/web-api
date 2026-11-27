<?php

declare(strict_types=1);

namespace Modules\Observability\Application\Commands;

final readonly class UpdateProfilerSettingsCommand
{
    /**
     * @param  array<string, mixed>  $values  Only the validated settings fields.
     */
    public function __construct(public array $values) {}
}
