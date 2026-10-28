<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Expense;
use App\Models\ExpenseAttachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseAttachment>
 */
class ExpenseAttachmentFactory extends Factory
{
    protected $model = ExpenseAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tenantId = Tenant::first()?->id ?? Tenant::create([
            'name' => fake()->company(),
            'legal_name' => fake()->company(),
            'country' => 'IT',
            'default_currency' => 'EUR',
        ])->id;

        return [
            'tenant_id' => $tenantId,
            'expense_id' => Expense::first()?->id ?? Expense::factory(),
            'attachment_id' => null,
            'disk' => config('filesystems.private_disk', 'local'),
            'path' => 'tenants/'.$tenantId.'/expenses/receipt_'.fake()->uuid().'.jpg',
            'original_name' => 'receipt_'.fake()->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => fake()->numberBetween(100000, 3000000),
            'is_receipt' => true,
            'ocr_raw_text' => null,
            'created_by' => User::first()?->id,
        ];
    }
}
