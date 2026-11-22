<?php

declare(strict_types=1);

namespace Modules\Projects\Support;

/**
 * Where a line of the project cost ledger comes from. The five first-class sources feed the actual cost; the purchase
 * commitment carries the open value of purchase orders (Mod 046) and the adjustment a manual correction.
 */
enum CostSourceType: string
{
    /** Approved timesheets (Mod 080). */
    case TimesheetLabor = 'TimesheetLabor';

    /** Approved employee expenses (Mod 075). */
    case EmployeeExpense = 'EmployeeExpense';

    /** Vendor invoices and goods receipts (Mod 048 / Mod 046). */
    case VendorInvoice = 'VendorInvoice';

    /** Warehouse material issues (Mod 051). */
    case WarehouseMaterialIssue = 'WarehouseMaterialIssue';

    /** Overhead pools of the cost centres allocated to the project (Mod 018). */
    case OverheadAllocation = 'OverheadAllocation';

    /** Open purchase orders (Mod 046): committed, not yet actual. */
    case PurchaseCommitment = 'PurchaseCommitment';

    /** Manual adjustment. */
    case Adjustment = 'Adjustment';

    /**
     * The ledger source of a job-cost entry, by its cost category.
     */
    public static function fromCostCategory(string $category): self
    {
        return match ($category) {
            'labour' => self::TimesheetLabor,
            'expense' => self::EmployeeExpense,
            'material' => self::WarehouseMaterialIssue,
            default => self::VendorInvoice,
        };
    }

    /**
     * The sources that make up the actual cost, in the order of the profitability waterfall.
     *
     * @return list<self>
     */
    public static function actualSources(): array
    {
        return [self::TimesheetLabor, self::EmployeeExpense, self::VendorInvoice, self::WarehouseMaterialIssue, self::OverheadAllocation, self::Adjustment];
    }
}
