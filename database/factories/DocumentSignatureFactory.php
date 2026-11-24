<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Contact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\DocumentTrust\Models\DocumentSignature;

/**
 * @extends Factory<DocumentSignature>
 */
final class DocumentSignatureFactory extends Factory
{
    protected $model = DocumentSignature::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = Str::random(256);

        return [
            'tenant_id' => Tenant::factory(),
            'document_type' => 'invoice',
            'document_id' => static fn (array $attributes): string => (string) SalesInvoiceFactory::new()->create([
                'tenant_id' => $attributes['tenant_id'],
                'contact_id' => Contact::factory()->create(['tenant_id' => $attributes['tenant_id']])->id,
            ])->id,
            'driver' => 'local',
            'format' => 'sha256-integrity',
            'content_sha256' => hash('sha256', $content),
            'signature' => base64_encode(hash('sha256', $content, true)),
        ];
    }
}
