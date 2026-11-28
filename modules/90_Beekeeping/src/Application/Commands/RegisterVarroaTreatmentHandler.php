<?php

declare(strict_types=1);

namespace Modules\Beekeeping\Application\Commands;

use Modules\Beekeeping\Models\VarroaTreatment;
use Modules\Beekeeping\Services\VarroaRegisterService;

final class RegisterVarroaTreatmentHandler
{
    public function __construct(private readonly VarroaRegisterService $service) {}

    public function handle(RegisterVarroaTreatmentCommand $command): VarroaTreatment
    {
        return $this->service->register($command->data);
    }
}
