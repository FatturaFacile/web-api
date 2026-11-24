<?php

declare(strict_types=1);

namespace Modules\Governance\Services;

/**
 * The segregation of duties rules most companies start from. A company adopts them with one call and then adjusts them.
 */
final class SodRuleCatalog
{
    /**
     * @return list<array{code: string, title: string, description: string, duties_a: list<string>, duties_b: list<string>, severity: string}>
     */
    public static function all(): array
    {
        return [
            [
                'code' => 'SOD-001',
                'title' => 'Approve supplier bank details and release payments',
                'description' => 'Whoever approves a change of supplier bank details must not release payments.',
                'duties_a' => ['supplier_portal.bank_approve'],
                'duties_b' => ['treasury.write'],
                'severity' => 'block',
            ],
            [
                'code' => 'SOD-002',
                'title' => 'Maintain suppliers and release payments',
                'description' => 'Creating suppliers and purchase orders together with releasing payments allows fictitious vendors to be paid.',
                'duties_a' => ['procurement.write', 'supplier_portal.write'],
                'duties_b' => ['treasury.write'],
                'severity' => 'warn',
            ],
            [
                'code' => 'SOD-003',
                'title' => 'Post journals and release payments',
                'description' => 'Posting to the general ledger together with moving money hides misappropriation.',
                'duties_a' => ['ledger.write', 'gl.journals.post'],
                'duties_b' => ['treasury.write'],
                'severity' => 'warn',
            ],
            [
                'code' => 'SOD-004',
                'title' => 'Maintain employees and run payroll',
                'description' => 'Whoever maintains the employee master data must not process the payroll.',
                'duties_a' => ['hr.write'],
                'duties_b' => ['payroll.write', 'payroll.*'],
                'severity' => 'block',
            ],
            [
                'code' => 'SOD-005',
                'title' => 'Adjust stock and approve governance decisions',
                'description' => 'Whoever adjusts the inventory must not accept the related risks or verify the remediation.',
                'duties_a' => ['inventory.write'],
                'duties_b' => ['governance.approve'],
                'severity' => 'warn',
            ],
        ];
    }
}
