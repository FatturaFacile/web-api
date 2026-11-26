<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Modules\Workflow\Actions\SeedApprovalEngineDefaultsAction;

/**
 * Starting configuration of the approval engine (Mod 006) for every tenant: Swiss and Italian business calendars with
 * their public holidays, the purchase order and expense report matrices and the expense report workflow. Existing rows are
 * never touched, so the seeder can run again. The texts of the screens live in WorkflowApprovalFrontendTranslations
 * (registered in FrontendTranslationSeeder), the messages of the API in the `workflow_*` language files.
 *
 * Not part of TestingSeeder: tests create what they need.
 */
final class WorkflowApprovalEngineSeeder extends Seeder
{
    /**
     * Seed every tenant.
     */
    public function run(): void
    {
        $action = app(SeedApprovalEngineDefaultsAction::class);

        Tenant::query()->each(static function (Tenant $tenant) use ($action): void {
            $action->execute((string) $tenant->id, (string) ($tenant->country ?? 'CH'));
        });
    }
}
