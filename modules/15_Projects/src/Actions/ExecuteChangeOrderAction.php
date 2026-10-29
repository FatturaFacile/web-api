<?php

declare(strict_types=1);

namespace Modules\Projects\Actions;

use Modules\Projects\Contracts\ProjectChangeOrderServiceInterface;
use Modules\Projects\Models\ProjectChangeOrder;

final readonly class ExecuteChangeOrderAction
{
    public function __construct(
        private ProjectChangeOrderServiceInterface $changeOrderService
    ) {}

    public function execute(ProjectChangeOrder $changeOrder, string $approvedBy): ProjectChangeOrder
    {
        return $this->changeOrderService->approveChangeOrder($changeOrder, $approvedBy);
    }
}
