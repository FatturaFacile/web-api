<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Inventory
|--------------------------------------------------------------------------
|
| adjustment_approval_threshold: the absolute net value (in the company currency) above which a manual inventory
| adjustment or the differences of a physical count wait for the INVENTORY_WRITE_OFF_APPROVAL workflow instead of being
| posted at once. It is the value the module has always used; it is an internal control setting of the company, not a
| legal value, and can be changed per installation with INVENTORY_ADJUSTMENT_APPROVAL_THRESHOLD.
|
*/

return [
    'adjustment_approval_threshold' => (string) env('INVENTORY_ADJUSTMENT_APPROVAL_THRESHOLD', '500.00'),
];
