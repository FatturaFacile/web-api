<?php

declare(strict_types=1);

namespace Modules\Projects\Contracts;

use Modules\Projects\DTOs\ChangeOrderDTO;
use Modules\Projects\Models\ProjectChangeOrder;

interface ProjectChangeOrderServiceInterface
{
    public function createChangeOrder(ChangeOrderDTO $dto): ProjectChangeOrder;

    public function approveChangeOrder(ProjectChangeOrder $changeOrder, string $approvedBy): ProjectChangeOrder;
}
