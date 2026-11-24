<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Treasury\Models\PaymentBatch;
use Modules\Treasury\Models\PaymentLine;

/**
 * @extends Factory<PaymentLine>
 */
final class PaymentLineFactory extends Factory
{
    protected $model = PaymentLine::class;

    public function definition(): array
    {
        $batch = PaymentBatch::first() ?? PaymentBatchFactory::new()->create();

        return [
            'id' => (string) Str::uuid7(),
            'payment_batch_id' => $batch->id,
            'supplier_id' => (string) Str::uuid7(),
            'invoice_id' => (string) Str::uuid7(),
            'end_to_end_id' => 'E2E-'.date('Ymd').'-'.strtoupper(Str::random(10)),
            'beneficiary_name' => $this->faker->company().' S.r.l.',
            'beneficiary_iban' => 'IT'.$this->faker->numerify('##').'Y'.$this->faker->numerify('######################'),
            'beneficiary_bic' => strtoupper($this->faker->lexify('????IT??')),
            'amount' => 2500.00,
            'currency' => 'EUR',
            'remittance_information' => 'Fattura n. '.$this->faker->numerify('INV-#####'),
            'status' => 'PENDING',
        ];
    }
}
