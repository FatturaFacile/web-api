<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\QuoteApprovalTier;
use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Default approval ladder of the CPQ lite quoting for every company that has none: a sales manager level and a sales
 * director level (the administrators of the company). Idempotent: a company that already has a level keeps it, and
 * the limits can be changed afterwards.
 */
class CpqDefaultCatalogAndRulesSeeder extends Seeder
{
    /**
     * @var list<array{level: int, name: string, approver_role: string, max_line_discount_percent: string, max_global_discount_percent: string, min_margin_percent: string}>
     */
    public const array DefaultTiers = [
        ['level' => 1, 'name' => 'Sales manager', 'approver_role' => 'sales_manager', 'max_line_discount_percent' => '10.00', 'max_global_discount_percent' => '10.00', 'min_margin_percent' => '25.00'],
        ['level' => 2, 'name' => 'Sales director', 'approver_role' => 'admin', 'max_line_discount_percent' => '20.00', 'max_global_discount_percent' => '20.00', 'min_margin_percent' => '15.00'],
    ];

    public function run(): void
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->get() as $tenant) {
            $context->runWithTenant($tenant, static function (): void {
                foreach (self::DefaultTiers as $tier) {
                    QuoteApprovalTier::withTrashed()->firstOrCreate(
                        ['level' => $tier['level']],
                        ['name' => $tier['name'], 'approver_role' => $tier['approver_role'], 'max_line_discount_percent' => $tier['max_line_discount_percent'], 'max_global_discount_percent' => $tier['max_global_discount_percent'], 'min_margin_percent' => $tier['min_margin_percent'], 'is_active' => true]
                    );
                }
            });
        }
    }
}
