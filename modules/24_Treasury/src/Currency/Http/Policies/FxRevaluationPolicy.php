<?php

declare(strict_types=1);

namespace Modules\Treasury\Currency\Http\Policies;

use App\Models\User;
use App\Policies\Concerns\ResolvesTenantMembership;
use App\Services\Tenancy\TenantContext;
use Modules\GeneralLedger\Models\LedgerSetting;
use Modules\Treasury\Currency\Infrastructure\Persistence\CurrencyFxRevaluation;

/**
 * Who simulates, posts and reverses a period-end revaluation: owners, administrators and accountants (or members granted
 * gl.* / currencies.*). Dual control: when the company requires approval of manual journals (four-eyes principle, ledger
 * settings), the person who simulated the revaluation cannot post it, another authorised person must.
 */
final class FxRevaluationPolicy
{
    use ResolvesTenantMembership;

    /** @var list<string> */
    public const array FinanceRoles = ['owner', 'admin', 'accountant'];

    public function viewAny(User $user): bool
    {
        return $this->hasRoleOrPermission($user, [...self::FinanceRoles, 'accountant_readonly'], ['currencies.read', 'currencies.*', 'gl.*']);
    }

    public function view(User $user, CurrencyFxRevaluation $revaluation): bool
    {
        return $this->viewAny($user) && $this->isOwnRecord($revaluation);
    }

    public function simulate(User $user): bool
    {
        return $this->hasRoleOrPermission($user, self::FinanceRoles, ['currencies.write', 'currencies.*', 'gl.*']);
    }

    public function post(User $user, CurrencyFxRevaluation $revaluation): bool
    {
        if (! $this->simulate($user) || ! $this->isOwnRecord($revaluation)) {
            return false;
        }

        return ! $this->requiresDualControl() || (string) $revaluation->created_by !== (string) $user->getKey();
    }

    public function reverse(User $user, CurrencyFxRevaluation $revaluation): bool
    {
        return $this->simulate($user) && $this->isOwnRecord($revaluation);
    }

    private function requiresDualControl(): bool
    {
        $tenantId = app(TenantContext::class)->getTenantId();

        return $tenantId !== null && (bool) LedgerSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->whereNull('deleted_at')->value('manual_journal_approval_required');
    }
}
