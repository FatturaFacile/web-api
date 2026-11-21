<?php

declare(strict_types=1);

namespace Modules\Platform\Identity\Providers;

use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Identity\Application\EffectivePermissionResolver;
use Modules\Platform\Identity\Application\FieldPolicyResolver;
use Modules\Platform\Identity\Application\Passkeys\PasskeyAuthenticator;
use Modules\Platform\Identity\Application\PermissionRegistry;
use Modules\Platform\Identity\Console\SyncPermissionsCommand;
use Modules\Platform\Identity\Domain\Enums\SystemRole as R;
use Modules\Platform\Identity\Domain\Services\AuthorizationEvaluator;
use Modules\Platform\Identity\Domain\ValueObjects\PermissionKey;
use Modules\Platform\Identity\Infrastructure\WebAuthn\WebAuthnLibAuthenticator;
use Modules\Platform\Identity\Listeners\RecordSecurityEvent;

/**
 * Wires the IAM engine into Laravel: permission-key abilities (`<module>.<resource>.<action>`) are decided by the
 * AuthorizationEvaluator, deny by default. Any other ability keeps going through the existing policies.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PermissionRegistry::class, function (): PermissionRegistry {
            $registry = new PermissionRegistry;
            $registry->register($this->corePermissions());

            return $registry;
        });
        $this->app->singleton(EffectivePermissionResolver::class);
        $this->app->singleton(AuthorizationEvaluator::class);
        $this->app->singleton(FieldPolicyResolver::class);
        $this->app->bind(PasskeyAuthenticator::class, WebAuthnLibAuthenticator::class);
    }

    public function boot(): void
    {
        Gate::before(function (mixed $user, string $ability, array $arguments = []): ?bool {
            // Abilities a module defines itself (Gate::define) keep their own rule: this engine decides only the ones nobody else defines.
            if (! $user instanceof User || ! PermissionKey::isValid($ability) || Gate::has($ability)) {
                return null;
            }

            $tenantId = app(TenantContext::class)->getTenantId();
            if ($tenantId === null) {
                return false;
            }

            $context = is_array($arguments[0] ?? null) ? $arguments[0] : [];
            $companyId = isset($context['company_id']) ? (string) $context['company_id'] : (request()->header('X-Company-Id') ?: null);
            $resolver = app(EffectivePermissionResolver::class);

            return app(AuthorizationEvaluator::class)->allows(
                $ability,
                $resolver->permissions($user, $tenantId, $companyId),
                $resolver->abacRules($user, $tenantId, $companyId),
                $context,
            );
        });

        Event::listen(RecordSecurityEvent::events(), [RecordSecurityEvent::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class]);
        }

        $this->app->booted(function (): void {
            $this->loadRoutesFrom(__DIR__.'/../../../routes/identity.php');
        });
    }

    /**
     * @return array<string, list<R>>
     */
    private function corePermissions(): array
    {
        $sales = [R::Sales, R::SalesManager, R::Accountant];

        return [
            'settings.users.view' => [], 'settings.users.create' => [], 'settings.users.update' => [], 'settings.users.delete' => [],
            'settings.roles.view' => [], 'settings.roles.create' => [], 'settings.roles.update' => [], 'settings.roles.delete' => [],
            'crm.contacts.view' => [R::Accountant, R::Sales, R::SalesManager, R::ProjectManager, R::Technician, R::Warehouse, R::Hr, R::Member, R::Viewer, R::ExternalAuditor],
            'crm.contacts.create' => $sales, 'crm.contacts.update' => $sales, 'crm.contacts.delete' => [R::SalesManager],
            'crm.contacts.export' => [R::SalesManager, R::Accountant], 'crm.contacts.view_sensitive' => [R::Accountant],
            'invoicing.invoices.view' => [R::Accountant, R::Sales, R::SalesManager, R::Viewer, R::ExternalAuditor],
            'invoicing.invoices.create' => $sales, 'invoicing.invoices.update' => $sales,
            'invoicing.invoices.approve' => [R::Accountant, R::SalesManager], 'invoicing.invoices.send_sdi' => [R::Accountant],
            'ledger.journals.view' => [R::Accountant, R::ExternalAuditor], 'ledger.journals.post' => [R::Accountant],
            'hr.employees.view' => [R::Hr], 'hr.employees.update' => [R::Hr],
            'hr.payroll.view' => [R::Hr], 'hr.payroll.view_sensitive' => [R::Hr],
        ];
    }
}
