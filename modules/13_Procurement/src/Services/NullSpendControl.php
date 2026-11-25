<?php

declare(strict_types=1);

namespace Modules\Procurement\Services;

use Modules\Procurement\Contracts\SpendControlInterface;
use Modules\Procurement\Models\PurchaseOrder;
use Modules\Procurement\Models\PurchaseRequisition;

/**
 * Default spend control: no budget module is bound, so nothing is checked and nothing is released.
 */
final class NullSpendControl implements SpendControlInterface
{
    public function check(PurchaseOrder|PurchaseRequisition $document, string $stage): void {}

    public function release(PurchaseOrder|PurchaseRequisition $document): void {}
}
