<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\DeliveryNote;
use App\Models\Invoice;
use App\Models\PassiveInvoice;
use App\Models\PurchaseOrder;
use App\Models\Quote;
use App\Models\SalesOrder;
use App\Models\ServiceReport;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Tenancy\TenantContext;
use Enterprise\MultiTenant\Services\ModuleAccessGate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Access to a record reached through a shared endpoint (attachments, document emails and PDFs), which cannot declare a
 * module on its route: the module of the record (config/permissions.php) and then the policy of the record decide.
 * Records without a module here (expenses) are decided by their policy alone.
 */
final class RecordAccess
{
    /** @var array<class-string<Model>, string> */
    private const array Modules = [
        Contact::class => 'crm',
        Invoice::class => 'invoicing',
        Quote::class => 'invoicing',
        SalesOrder::class => 'invoicing',
        DeliveryNote::class => 'invoicing',
        WorkOrder::class => 'service',
        ServiceReport::class => 'service',
        Ticket::class => 'service',
        Appointment::class => 'service',
        PurchaseOrder::class => 'procurement',
        PassiveInvoice::class => 'procurement',
    ];

    public function __construct(
        private readonly ModuleAccessGate $moduleAccess,
        private readonly TenantContext $tenantContext,
    ) {}

    /**
     * @param  'read'|'write'  $access
     * @param  string  $ability  the policy ability checked after the module, when the record has a policy
     *
     * @throws AuthorizationException
     */
    public function authorize(?User $user, Model $record, string $access, string $ability = 'view'): void
    {
        $module = self::Modules[$record::class] ?? null;

        if ($user === null || ($module !== null && ! $this->moduleAccess->allows($user, $this->tenantContext->getTenantId(), $module, $access))) {
            throw new AuthorizationException;
        }

        if (Gate::getPolicyFor($record) !== null) {
            Gate::forUser($user)->authorize($ability, $record);
        }
    }
}
