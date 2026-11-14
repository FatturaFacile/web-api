<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Treasury\Models\PagoPaPaymentNotice;
use Modules\Treasury\Services\PagoPaNoticeService;

/**
 * @extends Factory<PagoPaPaymentNotice>
 */
final class PagoPaPaymentNoticeFactory extends Factory
{
    protected $model = PagoPaPaymentNotice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $iuv = PagoPaNoticeService::buildIuv(fake()->unique()->numberBetween(1, 99999999999));

        return [
            'tenant_id' => Tenant::factory(),
            'debtor_name' => fake()->name(),
            'debtor_fiscal_code' => null,
            'amount' => '122.00',
            'currency' => 'EUR',
            'due_date' => now()->addDays(30)->toDateString(),
            'causale' => 'Invoice payment',
            'iuv' => $iuv,
            'notice_code' => PagoPaNoticeService::buildNoticeCode($iuv),
            'creditor_fiscal_code' => '12345678901',
            'status' => PagoPaPaymentNotice::Pending,
        ];
    }

    public function paid(): self
    {
        return $this->state(['status' => PagoPaPaymentNotice::Paid, 'paid_at' => now()]);
    }

    public function cancelled(): self
    {
        return $this->state(['status' => PagoPaPaymentNotice::Cancelled]);
    }
}
