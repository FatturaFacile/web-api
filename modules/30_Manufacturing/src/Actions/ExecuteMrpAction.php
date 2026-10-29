<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Actions;

use Modules\Manufacturing\Contracts\MrpServiceInterface;
use Modules\Manufacturing\DTOs\RunMrpDTO;
use Modules\Manufacturing\Models\MfgMrpRun;

final readonly class ExecuteMrpAction
{
    public function __construct(
        private MrpServiceInterface $mrpService
    ) {}

    public function execute(RunMrpDTO $dto): MfgMrpRun
    {
        return $this->mrpService->executeMrpRun($dto);
    }
}
