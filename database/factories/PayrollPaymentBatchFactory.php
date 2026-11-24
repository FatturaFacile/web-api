<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Payroll\Models\PayrollPaymentBatch;
use Modules\Payroll\Models\PayrollRun;

/**
 * @extends Factory<PayrollPaymentBatch>
 */
final class PayrollPaymentBatchFactory extends Factory
{
    protected $model = PayrollPaymentBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'payroll_run_id' => static fn (array $attributes): string => (string) PayrollRun::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            'payment_batch_id' => static fn (array $attributes): string => self::paymentBatchFor((string) $attributes['tenant_id']),
        ];
    }

    /**
     * PaymentBatchFactory looks up a bank account through the tenant scope, so the batch is built inside the tenant
     * context, after a bank account of that tenant exists.
     */
    private static function paymentBatchFor(string $tenantId): string
    {
        return app(TenantContext::class)->runFor(Tenant::query()->findOrFail($tenantId), static function () use ($tenantId): string {
            $bankAccount = BankAccountFactory::new()->create([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'bank_name' => 'Banca Stato del Cantone Ticino',
                'iban' => 'CH9300762011623852957',
                'currency' => 'CHF',
            ]);

            return (string) PaymentBatchFactory::new()->create([
                'tenant_id' => $tenantId,
                'company_id' => $tenantId,
                'bank_account_id' => $bankAccount->id,
                'currency' => 'CHF',
                'total_amount' => 48250.00,
                'item_count' => 9,
            ])->id;
        });
    }
}
