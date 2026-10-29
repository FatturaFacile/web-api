<?php

declare(strict_types=1);

namespace Modules\Inventory\Actions;

use Modules\Inventory\Contracts\InventoryCountServiceInterface;
use Modules\Inventory\Models\InventoryAdjustment;
use Modules\Inventory\Models\InventoryCountSession;

final readonly class ReconcileInventoryCountSessionAction
{
    public function __construct(
        private InventoryCountServiceInterface $service
    ) {}

    public function execute(InventoryCountSession $session, string $userId): InventoryAdjustment
    {
        return $this->service->reconcileSession($session, $userId);
    }
}
