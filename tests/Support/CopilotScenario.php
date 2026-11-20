<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Contact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Copilot\Contracts\LlmProviderInterface;
use Modules\Copilot\Llm\LlmProviderManager;

/**
 * Shared set-up of the Copilot tests.
 */
final class CopilotScenario
{
    public static function member(Tenant $tenant, string $role): User
    {
        $user = User::create(['name' => $role, 'email' => Str::uuid().'@example.com', 'password' => 'secret123']);
        $user->tenants()->attach($tenant->id, ['id' => (string) Str::uuid(), 'role' => $role]);

        return $user;
    }

    public static function actAs(object $test, User $user, Tenant $tenant): void
    {
        app(TenantContext::class)->clear();
        Sanctum::actingAs($user);
        $test->withHeader('X-Tenant-Id', $tenant->id);
    }

    public static function useModel(LlmProviderInterface $provider): void
    {
        config(['copilot.driver' => 'local']);
        app(LlmProviderManager::class)->register('local', $provider);
    }

    public static function invoice(Tenant $tenant, Contact $contact, string $number, string $net, string $date, string $paymentStatus = 'unpaid'): string
    {
        $id = (string) Str::uuid();

        DB::table('invoices')->insert([
            'id' => $id,
            'tenant_id' => $tenant->id,
            'contact_id' => $contact->id,
            'invoice_number' => $number,
            'date' => $date,
            'due_date' => $date,
            'total_net' => $net,
            'total_tax' => '0',
            'total_gross' => $net,
            'total_paid' => '0',
            'payment_status' => $paymentStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
