<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\SupplierPortal\Models\DocumentRequirement;
use Modules\SupplierPortal\Models\SupplierDocumentSubmission;

/**
 * @extends Factory<SupplierDocumentSubmission>
 */
final class SupplierDocumentSubmissionFactory extends Factory
{
    protected $model = SupplierDocumentSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'supplier_id' => (string) Str::uuid7(),
            'requirement_id' => DocumentRequirement::factory(),
            'status' => SupplierDocumentSubmission::Submitted,
            'expires_at' => now()->addYear()->toDateString(),
            'submitted_via' => 'internal',
        ];
    }
}
