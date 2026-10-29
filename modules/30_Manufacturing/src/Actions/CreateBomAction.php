<?php

declare(strict_types=1);

namespace Modules\Manufacturing\Actions;

use Modules\Manufacturing\Contracts\BillOfMaterialsServiceInterface;
use Modules\Manufacturing\DTOs\CreateBomDTO;
use Modules\Manufacturing\Models\MfgBom;

final readonly class CreateBomAction
{
    public function __construct(
        private BillOfMaterialsServiceInterface $bomService
    ) {}

    public function execute(CreateBomDTO $dto): MfgBom
    {
        return $this->bomService->createBom($dto);
    }
}
