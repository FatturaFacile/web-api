<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

final readonly class RegisterVarroaTreatmentCommand
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(public array $data) {}
}
