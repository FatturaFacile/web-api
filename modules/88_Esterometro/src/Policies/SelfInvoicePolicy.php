<?php

declare(strict_types=1);

namespace Modules\Esterometro\Policies;

use App\Models\User;
use Modules\Esterometro\Models\SelfInvoice;

final class SelfInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('esterometro.read');
    }

    public function view(User $user, SelfInvoice $selfInvoice): bool
    {
        return (string) $user->tenant_id === (string) $selfInvoice->tenant_id
            && $user->can('esterometro.read');
    }

    public function create(User $user): bool
    {
        return $user->can('esterometro.write');
    }

    public function update(User $user, SelfInvoice $selfInvoice): bool
    {
        return (string) $user->tenant_id === (string) $selfInvoice->tenant_id
            && $user->can('esterometro.write');
    }

    public function send(User $user, SelfInvoice $selfInvoice): bool
    {
        return (string) $user->tenant_id === (string) $selfInvoice->tenant_id
            && $user->can('esterometro.send');
    }

    public function delete(User $user, SelfInvoice $selfInvoice): bool
    {
        return (string) $user->tenant_id === (string) $selfInvoice->tenant_id
            && $user->can('esterometro.manage');
    }
}
