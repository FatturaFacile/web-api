<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\DocumentExpiryAlert;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;

/**
 * @extends Factory<DocumentExpiryAlert>
 */
final class DocumentExpiryAlertFactory extends Factory
{
    protected $model = DocumentExpiryAlert::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'submission_id' => SupplierDocumentSubmission::factory(),
            'threshold_days' => 30,
            'sent_at' => now(),
        ];
    }
}
